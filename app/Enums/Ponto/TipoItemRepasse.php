<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Sinal do item do repasse: o valor é sempre positivo.
 */
enum TipoItemRepasse: string implements HasColor, HasIcon, HasLabel
{
    case Provento = 'provento';
    case Desconto = 'desconto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Provento => 'Provento',
            self::Desconto => 'Desconto',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Provento => 'success',
            self::Desconto => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Provento => Heroicon::OutlinedPlusCircle,
            self::Desconto => Heroicon::OutlinedMinusCircle,
        };
    }
}
