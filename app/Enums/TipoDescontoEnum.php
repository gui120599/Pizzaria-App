<?php

namespace App\Enums;

enum TipoDescontoEnum: string
{
    case PERCENTUAL = 'percentual';
    case VALOR_FIXO = 'valor_fixo';

    public function label(): string
    {
        return match ($this) {
            self::PERCENTUAL => 'Percentual',
            self::VALOR_FIXO => 'Valor Fixo',
        };
    }
}
