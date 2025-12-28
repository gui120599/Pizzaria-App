<?php

namespace App\Observers;

use App\Models\MovimentacaoPedido;
use App\Models\Pedido;

class PedidoObserver
{
     /**
     * Handle the Pedido "updating" event.
     */
    public function updating(Pedido $pedido)
    {
        // verifica se o pedido teve alteração na sessão da mesa
        if ($pedido->isDirty('pedido_sessao_mesa_id')) {
            MovimentacaoPedido::create([
                'mov_pedido_pedido_id' => $pedido->id,
                'mov_pedido_sessao_mesa_id_anterior' => $pedido->getOriginal('pedido_sessao_mesa_id'),
                'mov_pedido_sessao_mesa_id_atual' => $pedido->pedido_sessao_mesa_id,
                'mov_pedido_user_id' => auth()->id(), // ou o usuário que fez a alteração
            ]);
        }
    }
}
