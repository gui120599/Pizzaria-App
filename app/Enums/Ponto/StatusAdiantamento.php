<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Situação do adiantamento em relação ao repasse.
 */
enum StatusAdiantamento: string implements HasColor, HasIcon, HasLabel
{
    case Aberto = 'aberto';
    case Descontado = 'descontado';
    case Cancelado = 'cancelado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aberto => 'Aberto',
            self::Descontado => 'Descontado',
            self::Cancelado => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aberto => 'warning',
            self::Descontado => 'success',
            self::Cancelado => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Aberto => Heroicon::OutlinedClock,
            self::Descontado => Heroicon::OutlinedCheckCircle,
            self::Cancelado => Heroicon::OutlinedXCircle,
        };
    }
}
