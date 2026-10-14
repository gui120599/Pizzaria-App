<?php

namespace App\Services\Cozinha;

use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\Cozinha\StatusProducaoPedido;
use App\Enums\TipoAtendimentoEnum;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\ProducaoItem;
use App\Models\User;
use App\Notifications\Cozinha\ProducaoProntaNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deriva o status de produção do pedido a partir dos itens (spec §3.4),
 * grava os caches (pedidos.pedido_producao_status e
 * itens_pedidos.item_pedido_producao_status, por query builder para não
 * disparar observers) e aplica no pedido real pelo PedidoStatusGateway.
 */
class SincronizadorPedidoProducao
{
    public function __construct(private PedidoStatusGateway $gateway) {}

    /**
     * Recalcula e aplica. Chamado pelo ProducaoService a cada transição e pelo
     * RoteadorProducao depois de gerar/cancelar itens.
     */
    public function recalcular(Pedido $pedido): StatusProducaoPedido
    {
        $anterior = $pedido->pedido_producao_status;
        $linhas = ProducaoItem::where('pedido_id', $pedido->id)->get();
        $novo = $this->statusDoPedido($pedido, $linhas);

        $this->gravarCaches($pedido, $novo, $linhas);
        $this->aplicarNoPedido($pedido, $anterior, $novo);

        return $novo;
    }

    /** @param  Collection<int, ProducaoItem>  $linhas */
    public function statusDoPedido(Pedido $pedido, Collection $linhas): StatusProducaoPedido
    {
        if ($linhas->isEmpty()) {
            return StatusProducaoPedido::SemProducao;
        }

        $vivas = $linhas->reject(fn (ProducaoItem $l) => $l->status === StatusItemProducao::Cancelado);

        if ($vivas->isEmpty()) {
            return $this->gateway->estaCancelado($pedido) ? StatusProducaoPedido::Cancelado : StatusProducaoPedido::SemProducao;
        }

        $ultimas = $vivas->filter(fn (ProducaoItem $l) => $l->ehUltimaEtapa());

        return match (true) {
            $ultimas->every(fn (ProducaoItem $l) => $l->status === StatusItemProducao::Expedido) => StatusProducaoPedido::Expedido,
            $ultimas->every(fn (ProducaoItem $l) => in_array($l->status, [StatusItemProducao::Pronto, StatusItemProducao::Expedido], true)) => StatusProducaoPedido::Pronto,
            // Qualquer linha já trabalhada (inclusive a refeita, agora cancelada) mantém o pedido "em produção".
            $linhas->contains(fn (ProducaoItem $l) => $l->iniciado_em !== null || $l->concluido_em !== null) => StatusProducaoPedido::EmProducao,
            default => StatusProducaoPedido::Aguardando,
        };
    }

    /**
     * Status de um item do pedido para o card do Painel de Pedidos: o da
     * linha menos adiantada da última etapa.
     *
     * @param  Collection<int, ProducaoItem>  $linhas  linhas do mesmo item do pedido
     */
    public function statusDoItem(Collection $linhas): StatusItemProducao
    {
        $vivas = $linhas->reject(fn (ProducaoItem $l) => $l->status === StatusItemProducao::Cancelado);

        if ($vivas->isEmpty()) {
            return StatusItemProducao::Cancelado;
        }

        $ultimas = $vivas->filter(fn (ProducaoItem $l) => $l->ehUltimaEtapa());

        return match (true) {
            $ultimas->every(fn (ProducaoItem $l) => $l->status === StatusItemProducao::Expedido) => StatusItemProducao::Expedido,
            $ultimas->every(fn (ProducaoItem $l) => in_array($l->status, [StatusItemProducao::Pronto, StatusItemProducao::Expedido], true)) => StatusItemProducao::Pronto,
            $vivas->contains(fn (ProducaoItem $l) => $l->iniciado_em !== null) => StatusItemProducao::EmPreparo,
            $vivas->contains(fn (ProducaoItem $l) => $l->status === StatusItemProducao::NaFila) => StatusItemProducao::NaFila,
            default => StatusItemProducao::Aguardando,
        };
    }

    /**
     * 1º item iniciado → pedido em preparo; tudo pronto sem Expedição →
     * pedido pronto (e aviso ao garçom). Com Expedição, quem leva a PRONTO é
     * o despacho (ProducaoService::expedirPedido).
     */
    private function aplicarNoPedido(Pedido $pedido, ?StatusProducaoPedido $anterior, StatusProducaoPedido $novo): void
    {
        if (in_array($novo, [StatusProducaoPedido::EmProducao, StatusProducaoPedido::Pronto], true)) {
            $this->gateway->marcarEmPreparo($pedido);
        }

        if ($novo === StatusProducaoPedido::Pronto && $anterior !== StatusProducaoPedido::Pronto && ! config('cozinha.usa_expedicao')) {
            $this->gateway->marcarPronto($pedido);
            $this->avisarGarcom($pedido);
        }
    }

    /** Garçom da mesa: itens prontos (sem Expedição) ou despachados (com Expedição). */
    public function avisarGarcom(Pedido $pedido): void
    {
        if ($pedido->tipoAtendimento() !== TipoAtendimentoEnum::MESA || $pedido->pedido_usuario_garcom_id === null) {
            return;
        }

        DB::afterCommit(function () use ($pedido): void {
            User::find($pedido->pedido_usuario_garcom_id)?->notify(new ProducaoProntaNotification($pedido->fresh()));
        });
    }

    /** @param  Collection<int, ProducaoItem>  $linhas */
    private function gravarCaches(Pedido $pedido, StatusProducaoPedido $status, Collection $linhas): void
    {
        $dados = ['pedido_producao_status' => $status->value];

        if ($status === StatusProducaoPedido::EmProducao && $pedido->pedido_producao_iniciada_em === null) {
            $dados['pedido_producao_iniciada_em'] = now();
        }

        if (in_array($status, [StatusProducaoPedido::Pronto, StatusProducaoPedido::Expedido], true)) {
            $dados['pedido_producao_iniciada_em'] = $pedido->pedido_producao_iniciada_em ?? now();
            $dados['pedido_producao_concluida_em'] = $pedido->pedido_producao_concluida_em ?? now();
        } elseif ($status === StatusProducaoPedido::EmProducao) {
            // Refazer/desfazer/item novo: o pedido volta a ter produção aberta.
            $dados['pedido_producao_concluida_em'] = null;
        }

        Pedido::whereKey($pedido->id)->update($dados);
        $pedido->forceFill($dados)->syncOriginalAttributes(array_keys($dados));

        foreach ($linhas->groupBy('item_pedido_id') as $itemId => $doItem) {
            ItensPedido::whereKey($itemId)->update(['item_pedido_producao_status' => $this->statusDoItem($doItem)->value]);
        }
    }
}
