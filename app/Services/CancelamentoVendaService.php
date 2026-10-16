<?php

namespace App\Services;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\MovimentacaoTipoEnum;
use App\Exceptions\NfeIoException;
use App\Models\HistoricoStatusPedido;
use App\Models\ItensPedido;
use App\Models\MovimentacaoProduto;
use App\Models\NfEmissao;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\Venda;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Desfaz uma venda já FINALIZADA (hoje: estorno total na maquininha Stone).
 *
 * - Itens lançados direto na venda voltam ao estoque (a baixa foi feita pelo
 *   VendaObserver ao finalizar).
 * - Itens vindos de pedido NÃO voltam: o pedido já foi produzido/entregue e o
 *   estoque dele foi baixado no preparo. O pedido sai de FINALIZADO e volta
 *   para a lista de recebimento do PDV; se não for cobrado de novo, o gerente
 *   cancela pela tabela de pedidos.
 * - A sessão de mesa finalizada volta para FECHADA (aparece no caixa).
 *
 * O caixa não precisa de ajuste aqui: o saldo soma só vendas FINALIZADAS.
 */
class CancelamentoVendaService
{
    public function __construct(private readonly FinalizacaoVendaService $finalizacao) {}

    public function cancelarFinalizada(Venda $venda, MotivoCancelamentoEnum $motivo, ?int $usuarioId = null): Venda
    {
        return DB::transaction(function () use ($venda, $motivo, $usuarioId) {
            $venda = Venda::whereKey($venda->id)->lockForUpdate()->firstOrFail();

            if ($venda->venda_status !== 'FINALIZADA') {
                return $venda;
            }

            // Pelo webhook não há usuário logado; a movimentação de estoque exige um.
            $usuarioId ??= Auth::id() ?? SessaoCaixa::whereKey($venda->venda_sessao_caixa_id)->value('sessaocaixa_user_id');

            $this->devolverEstoqueVendaDireta($venda, $usuarioId);
            $this->reabrirPedidosEMesas($venda);

            return $this->finalizacao->cancelar($venda, $motivo->value, $usuarioId);
        });
    }

    /**
     * Pede o cancelamento da NFC-e autorizada da venda. A confirmação chega
     * pelo webhook da NFe.io; recusa (ex.: fora do prazo da SEFAZ) volta como
     * exceção para quem chamou registrar.
     *
     * @throws NfeIoException
     */
    public function solicitarCancelamentoNfe(Venda $venda): bool
    {
        if ($venda->venda_status_nfe !== NfEmissao::STATUS_AUTORIZADA || blank($venda->venda_id_nfe)) {
            return false;
        }

        app(NfeIoService::class)->cancelar($venda->venda_id_nfe);

        $venda->update(['venda_status_nfe' => 'CancelamentoSolicitado']);
        NfEmissao::espelharStatus($venda->venda_id_nfe, 'CancelamentoSolicitado');

        return true;
    }

    /** Estorna exatamente as saídas que a finalização registrou para esta venda. */
    private function devolverEstoqueVendaDireta(Venda $venda, ?int $usuarioId): void
    {
        $saidas = MovimentacaoProduto::query()
            ->whereMorphedTo('referencia', $venda)
            ->where('mov_tipo', MovimentacaoTipoEnum::SAIDA)
            ->where('mov_origem', MovimentacaoOrigemEnum::VENDA)
            ->with('produto')
            ->get();

        $estoque = app(EstoqueService::class);

        foreach ($saidas as $saida) {
            if (! $saida->produto) {
                continue;
            }

            $estoque->registrarEntrada(
                $saida->produto,
                (float) $saida->mov_quantidade,
                (float) $saida->mov_custo_unitario,
                MovimentacaoOrigemEnum::AJUSTE,
                ['referencia' => $venda, 'motivo' => "Estorno de cancelamento — Venda #{$venda->id}", 'user_id' => $usuarioId],
            );
        }
    }

    private function reabrirPedidosEMesas(Venda $venda): void
    {
        $pedidoIds = ItensPedido::where('item_pedido_venda_id', $venda->id)
            ->whereNotNull('item_pedido_pedido_id')
            ->distinct()
            ->pluck('item_pedido_pedido_id');

        $pedidos = Pedido::where(fn ($q) => $q->whereIn('id', $pedidoIds)->orWhere('pedido_venda_id', $venda->id))
            ->where('pedido_status', '!=', 'CANCELADO')
            ->get();

        foreach ($pedidos as $pedido) {
            $dados = ['pedido_venda_id' => null, 'pedido_datahora_finalizado' => null];

            if ($pedido->pedido_status === 'FINALIZADO') {
                $dados['pedido_status'] = $this->statusAntesDeFinalizar($pedido);
            }

            $pedido->update($dados);
        }

        SessaoMesa::whereIn('id', $pedidos->pluck('pedido_sessao_mesa_id')->filter()->unique())
            ->where('sessao_mesa_status', 'FINALIZADA')
            ->update(['sessao_mesa_status' => 'FECHADA']);
    }

    /** FINALIZADO só é atingido a partir de ENTREGUE ou EM TRANSPORTE (ver VendaObserver). */
    private function statusAntesDeFinalizar(Pedido $pedido): string
    {
        return HistoricoStatusPedido::where('hsp_pedido_id', $pedido->id)
            ->where('hsp_status_para', 'FINALIZADO')
            ->latest('id')
            ->value('hsp_status_de') ?? 'ENTREGUE';
    }
}
