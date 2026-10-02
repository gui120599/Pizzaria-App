<?php

namespace App\Enums;

enum PedidoOrigemEnum: string
{
    case CARDAPIO = 'cardapio';
    case ATENDENTE = 'atendente';
    case MESA = 'mesa';
    /** Retirada lançada pelo garçom no Painel do Garçom, fora de sessão de mesa. */
    case GARCOM = 'garcom';

    public function label(): string
    {
        return match ($this) {
            self::CARDAPIO => 'Cardápio Online',
            self::ATENDENTE => 'Atendente',
            self::MESA => 'Mesa',
            self::GARCOM => 'Garçom (retirada)',
        };
    }
}
