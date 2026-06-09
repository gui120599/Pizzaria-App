<?php

namespace App\Observers;

use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\Venda;
use Carbon\Carbon;

class VendaObserver
{
    /**
     * Quando a venda é finalizada, finaliza automaticamente os pedidos e
     * sessões de mesa cujos itens estavam vinculados a esta venda.
     */
    public function updated(Venda $venda): void
    {
        if (! $venda->wasChanged('venda_status') || $venda->venda_status !== 'FINALIZADA') {
            return;
        }

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
                'pedido_venda_id'            => $venda->id,
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
}
