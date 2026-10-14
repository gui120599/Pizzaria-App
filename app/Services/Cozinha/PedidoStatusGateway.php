<?php

namespace App\Services\Cozinha;

use App\Enums\StatusPedidoEnum;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Models\Pedido;
use App\Services\PedidoStatusService;
use App\Support\Cozinha\SincronizacaoContexto;
use Illuminate\Support\Facades\Log;

/**
 * Único ponto do módulo de cozinha que conhece `pedidos.pedido_status`
 * (mapeado em config cozinha.pedido_status). As mudanças passam pelo
 * PedidoStatusService (baixa de estoque, histórico, lock) dentro do
 * SincronizacaoContexto, para o observer de produção não reagir à própria
 * cozinha. Nunca faz o pedido retroceder.
 */
class PedidoStatusGateway
{
    public function __construct(private PedidoStatusService $statusService) {}

    public function liberaProducao(Pedido $pedido): bool
    {
        return in_array($pedido->pedido_status, $this->config('libera_producao'), true);
    }

    public function estaCancelado(Pedido $pedido): bool
    {
        return in_array($pedido->pedido_status, $this->config('cancelado'), true);
    }

    /** O pedido já saiu da cozinha (em entrega, entregue, finalizado ou cancelado). */
    public function jaSaiuDaCozinha(Pedido $pedido): bool
    {
        return in_array($pedido->pedido_status, $this->config('nao_retroceder'), true);
    }

    /** O pedido está no status de "pronto" da cozinha (ao_concluir). */
    public function estaPronto(Pedido $pedido): bool
    {
        return $pedido->pedido_status === $this->config('ao_concluir');
    }

    /** Primeiro item iniciado: ABERTO → PREPARANDO (a baixa de estoque acontece aqui). */
    public function marcarEmPreparo(Pedido $pedido): void
    {
        $this->avancarAte($pedido, StatusPedidoEnum::from($this->config('ao_iniciar')));
    }

    /** Todos os itens prontos: até PRONTO, passando por PREPARANDO se preciso. */
    public function marcarPronto(Pedido $pedido): void
    {
        $this->avancarAte($pedido, StatusPedidoEnum::from($this->config('ao_concluir')));
    }

    /**
     * Despacho da Expedição: garante PRONTO e aplica ao_expedir do tipo de
     * atendimento (mesa → ENTREGUE). Delivery e retirada ficam em PRONTO: o
     * delivery sai pelo fluxo do entregador e a retirada quando o cliente busca.
     */
    public function marcarExpedido(Pedido $pedido): void
    {
        $this->marcarPronto($pedido);

        $destino = $this->config('ao_expedir')[$pedido->tipoAtendimento()->value] ?? null;

        if ($destino !== null) {
            $this->avancarAte($pedido, StatusPedidoEnum::from($destino));
        }
    }

    /**
     * Sobe degrau a degrau (StatusPedidoEnum::proximo) até o destino, sem
     * nunca voltar: status já igual ou além do destino não muda nada.
     */
    private function avancarAte(Pedido $pedido, StatusPedidoEnum $destino): void
    {
        SincronizacaoContexto::executando(function () use ($pedido, $destino): void {
            for ($degraus = 0; $degraus < 6; $degraus++) {
                $pedido->refresh();
                $atual = $pedido->status();

                if ($atual === null || $atual === $destino || $this->jaSaiuDaCozinha($pedido) || ! $this->vemAntes($atual, $destino, $pedido)) {
                    return;
                }

                try {
                    $this->statusService->avancar($pedido);
                } catch (TransicaoPedidoInvalidaException $e) {
                    // Outro operador mexeu no pedido ao mesmo tempo: a cozinha não insiste.
                    Log::info('Cozinha: pedido não avançou.', ['pedido_id' => $pedido->id, 'destino' => $destino->value, 'erro' => $e->getMessage()]);

                    return;
                }
            }
        });
    }

    /** $atual vem antes de $destino no fluxo deste pedido (INICIADO nunca é tocado pela cozinha)? */
    private function vemAntes(StatusPedidoEnum $atual, StatusPedidoEnum $destino, Pedido $pedido): bool
    {
        if ($atual === StatusPedidoEnum::INICIADO) {
            return false;
        }

        $passo = $atual;

        while (($passo = $passo->proximo($pedido->exigeEntrega())) !== null) {
            if ($passo === $destino) {
                return true;
            }
        }

        return false;
    }

    private function config(string $chave): mixed
    {
        return config("cozinha.pedido_status.{$chave}");
    }
}
