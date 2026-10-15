<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Ciclo de vida do repasse. Pago só volta por estorno manual no financeiro.
 */
enum StatusRepasse: string implements HasColor, HasIcon, HasLabel
{
    case Rascunho = 'rascunho';
    case Aprovado = 'aprovado';
    case Pago = 'pago';
    case Cancelado = 'cancelado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Aprovado => 'Aprovado',
            self::Pago => 'Pago',
            self::Cancelado => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Rascunho => 'gray',
            self::Aprovado => 'info',
            self::Pago => 'success',
            self::Cancelado => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Rascunho => Heroicon::OutlinedPencil,
            self::Aprovado => Heroicon::OutlinedCheckCircle,
            self::Pago => Heroicon::OutlinedBanknotes,
            self::Cancelado => Heroicon::OutlinedXCircle,
        };
    }
}
