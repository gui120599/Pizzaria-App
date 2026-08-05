<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatusFechamentoCaixa: string implements HasColor, HasLabel
{
    case Rascunho = 'rascunho';
    case Confirmado = 'confirmado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Confirmado => 'Confirmado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Rascunho => 'warning',
            self::Confirmado => 'success',
        };
    }
}
