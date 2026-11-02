<?php

namespace App\Observers;

use App\Enums\StatusPedidoEnum;
use App\Models\Pedido;
use App\Services\Push\NotificadorGarcom;
use App\Support\Push\EventoGarcom;

/**
 * Pedido que passa a PRONTO avisa o garçom: rodada de mesa ou retirada
 * lançada por ele. Delivery e balcão não entram. Todo caminho que muda o
 * status usa save() (PedidoStatusService, gateway da cozinha, telas legadas).
 */
class NotificacaoGarcomObserver
{
    public function updated(Pedido $pedido): void
    {
        if (! $pedido->wasChanged('pedido_status') || $pedido->pedido_status !== StatusPedidoEnum::PRONTO->value) {
            return;
        }

        if ($pedido->pedido_sessao_mesa_id === null && ! EventoGarcom::ehRetiradaDoGarcom($pedido)) {
            return;
        }

        app(NotificadorGarcom::class)->notificar(EventoGarcom::pronto($pedido));
    }
}
