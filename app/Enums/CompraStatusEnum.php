<?php

namespace App\Enums;

enum CompraStatusEnum: string
{
    case RASCUNHO = 'rascunho';
    case CONFIRMADA = 'confirmada';
    case CANCELADA = 'cancelada';
    case SEM_EFEITO = 'sem_efeito';

    public function label(): string
    {
        return match ($this) {
            self::RASCUNHO => 'Rascunho',
            self::CONFIRMADA => 'Confirmada',
            self::CANCELADA => 'Cancelada',
            self::SEM_EFEITO => 'Sem efeito (devolução registrada)',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::RASCUNHO => 'warning',
            self::CONFIRMADA => 'success',
            self::CANCELADA => 'danger',
            self::SEM_EFEITO => 'gray',
        };
    }
}
