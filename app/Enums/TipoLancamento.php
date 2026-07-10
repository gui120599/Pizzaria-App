<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum TipoLancamento: string implements HasColor, HasIcon, HasLabel
{
    case Pagar = 'pagar';
    case Receber = 'receber';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pagar => 'A Pagar',
            self::Receber => 'A Receber',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pagar => 'danger',
            self::Receber => 'success',
        };
    }

    public function getIcon(): string|BackedEnum|null
    {
        return match ($this) {
            self::Pagar => Heroicon::OutlinedArrowUpCircle,
            self::Receber => Heroicon::OutlinedArrowDownCircle,
        };
    }
}
