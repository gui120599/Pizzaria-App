<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Natureza do adiantamento. Só vale/outro tiram dinheiro de uma conta; consumo e saldo devedor só descontam no repasse.
 */
enum NaturezaAdiantamento: string implements HasColor, HasIcon, HasLabel
{
    case Vale = 'vale';
    case Consumo = 'consumo';
    case SaldoDevedor = 'saldo_devedor';
    case Outro = 'outro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vale => 'Vale',
            self::Consumo => 'Consumo interno',
            self::SaldoDevedor => 'Saldo devedor de período anterior',
            self::Outro => 'Outro',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Vale => 'warning',
            self::Consumo => 'info',
            self::SaldoDevedor => 'danger',
            self::Outro => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Vale => Heroicon::OutlinedBanknotes,
            self::Consumo => Heroicon::OutlinedShoppingBag,
            self::SaldoDevedor => Heroicon::OutlinedArrowPath,
            self::Outro => Heroicon::OutlinedEllipsisHorizontalCircle,
        };
    }

    /** Sai dinheiro de uma conta financeira (exige "Sai de"). */
    public function saiDinheiro(): bool
    {
        return in_array($this, [self::Vale, self::Outro], true);
    }
}
