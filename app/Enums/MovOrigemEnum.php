<?php

namespace App\Enums;

enum MovOrigemEnum: string
{
    case VENDA = 'venda';
    case COMPRA = 'compra';
    case AJUSTE = 'ajuste';
    case PRODUCAO = 'producao';
    case CANCELAMENTO = 'cancelamento';

    public function label(): string
    {
        return match($this) {
            self::VENDA => 'Venda',
            self::COMPRA => 'Compra',
            self::AJUSTE => 'Ajuste Manual',
            self::PRODUCAO => 'Produção',
            self::CANCELAMENTO => 'Cancelamento de Venda',
        };
    }
}
