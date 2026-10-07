<?php

namespace App\Enums;

/**
 * Por que um pedido do QR da mesa precisou da aprovação do garçom — gravado
 * em pedidos.pedido_aprovacao_motivos e mostrado ao garçom ao aprovar.
 */
enum MotivoAprovacaoMesaEnum: string
{
    case PRIMEIRO_PEDIDO = 'PRIMEIRO_PEDIDO';
    case PRODUTO_MARCADO = 'PRODUTO_MARCADO';
    case QUANTIDADE_ALTA = 'QUANTIDADE_ALTA';

    public function label(): string
    {
        return match ($this) {
            self::PRIMEIRO_PEDIDO => 'Primeiro pedido',
            self::PRODUTO_MARCADO => 'Produto com aprovação',
            self::QUANTIDADE_ALTA => 'Quantidade alta',
        };
    }
}
