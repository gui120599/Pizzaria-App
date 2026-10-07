<?php

namespace App\Enums;

enum StatusChamadoMesaEnum: string
{
    case PENDENTE = 'PENDENTE';
    case ATENDIDO = 'ATENDIDO';
    /** A mesa fechou (ou a conta foi aberta por outro caminho) antes de alguém atender. */
    case CANCELADO = 'CANCELADO';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::ATENDIDO => 'Atendido',
            self::CANCELADO => 'Cancelado',
        };
    }
}
