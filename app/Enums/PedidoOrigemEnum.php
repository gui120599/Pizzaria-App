<?php

namespace App\Enums;

enum PedidoOrigemEnum: string
{
    case CARDAPIO = 'cardapio';
    case ATENDENTE = 'atendente';
    case MESA = 'mesa';

    public function label(): string
    {
        return match ($this) {
            self::CARDAPIO => 'Cardápio Online',
            self::ATENDENTE => 'Atendente',
            self::MESA => 'Mesa',
        };
    }
}
