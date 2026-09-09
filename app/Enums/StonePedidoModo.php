<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Modelo de criação do pedido na Connect Stone.
 *
 * - Direto: o POST /orders já leva payment_setup (tipo crédito/débito/PIX +
 *   parcelas). A maquininha pula direto para a tela de pagamento daquele tipo.
 *   É o fluxo do balcão (a forma de pagamento no PDV já diz o tipo).
 * - Listado: POST /orders sem payment_setup. O pedido entra numa lista no POS
 *   e o operador/entregador seleciona e escolhe o tipo na própria maquininha.
 *   É o fluxo de recebimento na entrega.
 */
enum StonePedidoModo: string implements HasColor, HasLabel
{
    case Direto = 'direto';
    case Listado = 'listado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Direto => 'Direto',
            self::Listado => 'Listado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Direto => 'primary',
            self::Listado => 'gray',
        };
    }
}
