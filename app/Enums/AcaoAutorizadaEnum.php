<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Ações sensíveis que exigem autorização de gerente (PIN) quando o operador
 * não tem a permissão — gravadas em autorizacoes_gerente.
 */
enum AcaoAutorizadaEnum: string implements HasLabel
{
    case CANCELAR_ITEM = 'CANCELAR_ITEM';
    case REMOVER_TAXA_SERVICO = 'REMOVER_TAXA_SERVICO';
    case TRANSFERIR_MESA = 'TRANSFERIR_MESA';
    case CONCEDER_CORTESIA = 'CONCEDER_CORTESIA';

    public function getLabel(): string
    {
        return match ($this) {
            self::CANCELAR_ITEM => 'Cancelar item',
            self::REMOVER_TAXA_SERVICO => 'Remover taxa de serviço',
            self::TRANSFERIR_MESA => 'Transferir mesa',
            self::CONCEDER_CORTESIA => 'Conceder cortesia',
        };
    }

    /** Permissão que dispensa o PIN de gerente para esta ação. */
    public function permissao(): string
    {
        return match ($this) {
            self::CANCELAR_ITEM => 'cancelar_item:pedido',
            self::REMOVER_TAXA_SERVICO => 'remover_taxa:sessao_mesa',
            self::TRANSFERIR_MESA => 'transferir:sessao_mesa',
            self::CONCEDER_CORTESIA => 'conceder_cortesia:pedido',
        };
    }
}
