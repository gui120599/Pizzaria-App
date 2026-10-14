<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasLabel;

enum TamanhoFonteCozinha: string implements HasLabel
{
    case Normal = 'normal';
    case Grande = 'grande';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Grande => 'Grande',
        };
    }
}
