<?php

namespace App\Services;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Carbon;

class EntregaService
{
    /**
     * Determina o que fazer com o pedido pro entregador logado: aceitar
     * (assumir a entrega), entregar (encerrar uma entrega já assumida por
     * ele) ou indisponível (qualquer outro estado — já entregue, com outro
     * entregador, ainda não está pronto etc).
     */
    public function proximaAcao(Pedido $pedido, User $entregador): string
    {
        if ($pedido->pedido_status === 'PRONTO' && $pedido->pedido_usuario_entrega_id === null) {
            return 'aceitar';
        }

        if ($pedido->pedido_status === 'EM TRANSPORTE' && $pedido->pedido_usuario_entrega_id === $entregador->id) {
            return 'entregar';
        }

        return 'indisponivel';
    }

    /**
     * Motivo legível de por que o pedido está indisponível pro entregador —
     * usado na tela de confirmação do scan.
     */
    public function motivoIndisponivel(Pedido $pedido, User $entregador): string
    {
        return match (true) {
            in_array($pedido->pedido_status, ['ENTREGUE', 'FINALIZADO'], true) => 'Este pedido já foi entregue.',
            $pedido->pedido_status === 'CANCELADO' => 'Este pedido foi cancelado.',
            $pedido->pedido_status === 'EM TRANSPORTE' => 'Este pedido já está com outro entregador.',
            default => 'Este pedido ainda não está pronto pra saída.',
        };
    }

    /**
     * Claim atômico: só avança se ninguém pegou o pedido antes (evita dois
     * entregadores aceitando o mesmo pedido ao mesmo tempo).
     */
    public function aceitar(int $pedidoId, User $entregador): bool
    {
        $claimed = Pedido::where('id', $pedidoId)
            ->where('pedido_status', 'PRONTO')
            ->whereNull('pedido_usuario_entrega_id')
            ->update([
                'pedido_status' => 'EM TRANSPORTE',
                'pedido_datahora_transporte' => Carbon::now(),
                'pedido_usuario_entrega_id' => $entregador->id,
            ]);

        return $claimed > 0;
    }

    /**
     * Só o entregador atribuído ao pedido consegue encerrar a entrega.
     */
    public function marcarEntregue(int $pedidoId, User $entregador): bool
    {
        $pedido = Pedido::where('id', $pedidoId)
            ->where('pedido_status', 'EM TRANSPORTE')
            ->where('pedido_usuario_entrega_id', $entregador->id)
            ->first();

        if (! $pedido) {
            return false;
        }

        // Mesma regra de PedidoController::AvancarPedidoEntregue: se o
        // pedido já tem venda finalizada/paga, o status final é FINALIZADO.
        $pedido->update([
            'pedido_status' => $pedido->pedido_datahora_finalizado ? 'FINALIZADO' : 'ENTREGUE',
            'pedido_datahora_entrega' => Carbon::now(),
        ]);

        return true;
    }
}
