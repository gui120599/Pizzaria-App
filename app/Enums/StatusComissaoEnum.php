<?php

namespace App\Enums;

enum StatusComissaoEnum: string
{
    case PENDENTE = 'pendente';
    case CREDITADA = 'creditada';
    case ESTORNADA = 'estornada';
    case CANCELADA = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::CREDITADA => 'Creditada na carteira',
            self::ESTORNADA => 'Estornada',
            self::CANCELADA => 'Cancelada',
        };
    }
}
