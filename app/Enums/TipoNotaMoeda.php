<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TipoNotaMoeda: string implements HasLabel
{
    case Cedula = 'cedula';
    case Moeda = 'moeda';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cedula => 'Cédula',
            self::Moeda => 'Moeda',
        };
    }
}
