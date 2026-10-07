<?php

namespace App\Enums;

enum PedidoOrigemEnum: string
{
    case CARDAPIO = 'cardapio';
    case ATENDENTE = 'atendente';
    case MESA = 'mesa';
    /** Retirada lançada pelo garçom no Painel do Garçom, fora de sessão de mesa. */
    case GARCOM = 'garcom';
    /** Pedido feito pelo próprio cliente no QR da mesa, dentro da sessão da mesa. */
    case MESA_QR = 'mesa_qr';

    public function label(): string
    {
        return match ($this) {
            self::CARDAPIO => 'Cardápio Online',
            self::ATENDENTE => 'Atendente',
            self::MESA => 'Mesa',
            self::GARCOM => 'Garçom (retirada)',
            self::MESA_QR => 'Mesa (QR do cliente)',
        };
    }
}
