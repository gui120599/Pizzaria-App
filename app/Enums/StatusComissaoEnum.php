<?php

namespace App\Enums;

enum StatusComissaoEnum: string
{
    case PENDENTE = 'pendente';
    case APROVADA = 'aprovada';
    case PAGA = 'paga';
    case CANCELADA = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::APROVADA => 'Aprovada',
            self::PAGA => 'Paga',
            self::CANCELADA => 'Cancelada',
        };
    }
}
