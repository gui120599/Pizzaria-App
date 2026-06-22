<?php

namespace App\Enums;

enum CompraStatusEnum: string
{
    case RASCUNHO = 'rascunho';
    case CONFIRMADA = 'confirmada';
    case CANCELADA = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::RASCUNHO => 'Rascunho',
            self::CONFIRMADA => 'Confirmada',
            self::CANCELADA => 'Cancelada',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::RASCUNHO => 'warning',
            self::CONFIRMADA => 'success',
            self::CANCELADA => 'danger',
        };
    }
}
