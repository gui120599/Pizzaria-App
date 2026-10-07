<?php

namespace App\Enums;

/**
 * Aprovação, pelo garçom, de um pedido feito pelo cliente no QR da mesa.
 * Null no pedido = não passou por aprovação (foi direto para a cozinha ou
 * não veio do QR). Pendente = pedido ainda INICIADO, fora da conta e da cozinha.
 */
enum StatusAprovacaoPedidoEnum: string
{
    case PENDENTE = 'PENDENTE';
    case APROVADO = 'APROVADO';
    case RECUSADO = 'RECUSADO';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Aguardando aprovação',
            self::APROVADO => 'Aprovado',
            self::RECUSADO => 'Recusado',
        };
    }
}
