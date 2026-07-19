<?php

namespace App\Observers;

use App\Enums\MovimentacaoOrigemEnum;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\Venda;
use App\Services\EstoqueService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class VendaObserver
{
    /**
     * Quando a venda é finalizada:
     * - Finaliza automaticamente os pedidos e sessões de mesa vinculados.
     * - Baixa o estoque dos ItensVenda que vieram de venda direta (sem pedido),
     *   evitando dupla baixa dos itens que já passaram por PREPARANDO no pedido.
     */
    public function updated(Venda $venda): void
    {
        if (! $venda->wasChanged('venda_status') || $venda->venda_status !== 'FINALIZADA') {
            return;
        }

        $this->finalizarPedidosEMesas($venda);
        $this->baixarEstoqueVendaDireta($venda);
    }

    private function finalizarPedidosEMesas(Venda $venda): void
    {
        // Busca todos os ItensPedido lançados nesta venda
        $pedidoIds = ItensPedido::where('item_pedido_venda_id', $venda->id)
            ->distinct()
            ->pluck('item_pedido_pedido_id');

        if ($pedidoIds->isEmpty()) {
            return;
        }

        $pedidos = Pedido::whereIn('id', $pedidoIds)
            ->where('pedido_status', '!=', 'CANCELADO')
            ->get();

        $sessaoMesaIds = [];

        foreach ($pedidos as $pedido) {
            // Só finaliza o pedido se não houver itens ainda sem venda atribuída
            $temItensNaoLancados = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->whereNull('item_pedido_venda_id')
                ->exists();

            if ($temItensNaoLancados) {
                continue;
            }

            $dados = [
                'pedido_venda_id' => $venda->id,
                'pedido_datahora_finalizado' => Carbon::now(),
            ];

            if (in_array($pedido->pedido_status, ['ENTREGUE', 'EM TRANSPORTE'])) {
                $dados['pedido_status'] = 'FINALIZADO';
            }

            $pedido->updateQuietly($dados);

            if ($pedido->pedido_sessao_mesa_id) {
                $sessaoMesaIds[] = $pedido->pedido_sessao_mesa_id;
            }
        }

        // Finaliza as sessões de mesa únicas e libera as mesas
        foreach (array_unique($sessaoMesaIds) as $sessaoMesaId) {
            $sessaoMesa = SessaoMesa::find($sessaoMesaId);

            if (! $sessaoMesa || $sessaoMesa->sessao_mesa_status === 'FINALIZADA') {
                continue;
            }

            // Verifica se todos os pedidos não-cancelados da mesa estão finalizados
            $pendentes = Pedido::where('pedido_sessao_mesa_id', $sessaoMesaId)
                ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
                ->exists();

            if ($pendentes) {
                continue;
            }

            $sessaoMesa->update(['sessao_mesa_status' => 'FINALIZADA']);

            $mesa = Mesa::find($sessaoMesa->sessao_mesa_mesa_id);
            if ($mesa) {
                $mesa->update(['mesa_status' => 'LIBERADA']);
            }
        }
    }

    /**
     * Baixa estoque apenas dos ItensVenda que NÃO vieram de pedidos.
     *
     * Itens originados de pedidos já tiveram o estoque baixado ao entrar em
     * PREPARANDO (via PedidoObserver), então são excluídos aqui para evitar
     * dupla baixa.
     */
    private function baixarEstoqueVendaDireta(Venda $venda): void
    {
        // Produto IDs que já foram baixados via pedido vinculado a esta venda
        $produtosVindosDePedido = ItensPedido::where('item_pedido_venda_id', $venda->id)
            ->pluck('item_pedido_produto_id')
            ->unique()
            ->toArray();

        $itens = $venda->itensVenda()
            ->whereNotIn('item_venda_produto_id', $produtosVindosDePedido)
            ->where('item_venda_status', 'INSERIDO')
            ->with(['produto', 'produto.fichaItens', 'produto.fichaItens.insumo'])
            ->get();

        if ($itens->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($itens, $venda) {
            $service = app(EstoqueService::class);

            foreach ($itens as $item) {
                $produto = $item->produto;
                $quantidade = (float) $item->item_venda_quantidade;

                if (! $produto) {
                    continue;
                }

                $opts = [
                    'referencia' => $venda,
                    'motivo' => "Baixa venda direta #{$venda->id}",
                ];

                foreach ($service->itensConsumo($produto, $quantidade) as $consumo) {
                    $insumo = $consumo['produto'];

                    if (! $insumo->produto_controla_estoque) {
                        continue;
                    }

                    $service->registrarSaida($insumo, $consumo['quantidade'], MovimentacaoOrigemEnum::VENDA, $opts);
                }
            }
        });
    }
}
