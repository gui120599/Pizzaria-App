<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Comportamento: string implements HasColor, HasLabel
{
    case Fixo = 'fixo';
    case Variavel = 'variavel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixo => 'Fixo',
            self::Variavel => 'Variável',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Fixo => 'info',
            self::Variavel => 'warning',
        };
    }
}
