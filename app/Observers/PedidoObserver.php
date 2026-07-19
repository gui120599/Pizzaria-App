<?php

namespace App\Observers;

use App\Enums\MovimentacaoOrigemEnum;
use App\Models\ItensPedido;
use App\Models\MovimentacaoPedido;
use App\Models\Pedido;
use App\Services\EstoqueService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PedidoObserver
{
    // Statuses que indicam que a baixa de estoque já foi realizada
    private const STATUSES_COM_BAIXA = ['PREPARANDO', 'PRONTO', 'EM TRANSPORTE', 'ENTREGUE', 'FINALIZADO'];

    /**
     * Handle the Pedido "updating" event.
     */
    public function updating(Pedido $pedido): void
    {
        if ($pedido->isDirty('pedido_sessao_mesa_id')) {
            MovimentacaoPedido::create([
                'mov_pedido_pedido_id' => $pedido->id,
                'mov_pedido_sessao_mesa_id_anterior' => $pedido->getOriginal('pedido_sessao_mesa_id'),
                'mov_pedido_sessao_mesa_id_atual' => $pedido->pedido_sessao_mesa_id,
                'mov_pedido_user_id' => Auth::id(),
            ]);
        }

        if (! $pedido->isDirty('pedido_status')) {
            return;
        }

        $novoStatus = $pedido->pedido_status;
        $statusAnterior = $pedido->getOriginal('pedido_status');

        // Baixa de estoque: pedido entrou em preparo (momento real de consumo dos insumos)
        if ($novoStatus === 'PREPARANDO') {
            $this->baixarEstoque($pedido);
        }

        // Estorno: pedido cancelado após ter saído do estado PREPARANDO
        if ($novoStatus === 'CANCELADO' && in_array($statusAnterior, self::STATUSES_COM_BAIXA, true)) {
            $this->estornarEstoque($pedido);
        }
    }

    private function baixarEstoque(Pedido $pedido): void
    {
        $itens = $this->carregarItens($pedido);

        DB::transaction(function () use ($itens, $pedido) {
            $service = app(EstoqueService::class);
            foreach ($itens as $item) {
                $this->processarBaixaItem(
                    $item,
                    $pedido,
                    $service,
                    "Baixa automática — Pedido #{$pedido->id}",
                );
            }
        });
    }

    private function estornarEstoque(Pedido $pedido): void
    {
        $itens = $this->carregarItens($pedido);

        DB::transaction(function () use ($itens, $pedido) {
            $service = app(EstoqueService::class);
            foreach ($itens as $item) {
                $this->processarEstornoItem(
                    $item,
                    $pedido,
                    $service,
                    "Estorno de cancelamento — Pedido #{$pedido->id}",
                );
            }
        });
    }

    private function processarBaixaItem(
        ItensPedido $item,
        Pedido $pedido,
        EstoqueService $service,
        string $motivo,
    ): void {
        $produto = $item->produto;
        $quantidade = (float) $item->item_pedido_quantidade;

        if (! $produto) {
            return;
        }

        $opts = ['referencia' => $pedido, 'motivo' => $motivo];

        foreach ($service->itensConsumo($produto, $quantidade) as $consumo) {
            $insumo = $consumo['produto'];

            if (! $insumo->produto_controla_estoque) {
                continue;
            }

            $service->registrarSaida($insumo, $consumo['quantidade'], MovimentacaoOrigemEnum::VENDA, $opts);
        }
    }

    private function processarEstornoItem(
        ItensPedido $item,
        Pedido $pedido,
        EstoqueService $service,
        string $motivo,
    ): void {
        $produto = $item->produto;
        $quantidade = (float) $item->item_pedido_quantidade;

        if (! $produto) {
            return;
        }

        $opts = ['referencia' => $pedido, 'motivo' => $motivo];

        foreach ($service->itensConsumo($produto, $quantidade) as $consumo) {
            $insumo = $consumo['produto'];

            if (! $insumo->produto_controla_estoque) {
                continue;
            }

            $service->registrarEntrada(
                $insumo,
                $consumo['quantidade'],
                (float) $insumo->produto_custo_medio,
                MovimentacaoOrigemEnum::AJUSTE,
                $opts,
            );
        }
    }

    private function carregarItens(Pedido $pedido): \Illuminate\Database\Eloquent\Collection
    {
        return $pedido->item_pedido_pedido_id()
            ->where('item_pedido_status', 'INSERIDO')
            ->with(['produto', 'produto.fichaItens', 'produto.fichaItens.insumo'])
            ->get();
    }
}
