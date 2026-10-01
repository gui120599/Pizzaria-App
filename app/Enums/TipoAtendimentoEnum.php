<?php

namespace App\Enums;

/**
 * Como o pedido é atendido — derivado, não é uma coluna nova: mesa se tem
 * sessão de mesa; delivery se a opção de entrega exige endereço; senão
 * retirada. Usado pro filtro rápido e pro badge do card no Painel de Pedidos.
 */
enum TipoAtendimentoEnum: string
{
    case DELIVERY = 'delivery';
    case RETIRADA = 'retirada';
    case MESA = 'mesa';

    public function label(): string
    {
        return match ($this) {
            self::DELIVERY => 'Delivery',
            self::RETIRADA => 'Retirada',
            self::MESA => 'Mesa',
        };
    }
}
