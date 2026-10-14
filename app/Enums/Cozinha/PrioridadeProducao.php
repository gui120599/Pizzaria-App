<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum PrioridadeProducao: string implements HasColor, HasIcon, HasLabel
{
    case Normal = 'normal';
    case Alta = 'alta';
    case Urgente = 'urgente';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Alta => 'Alta',
            self::Urgente => 'Urgente',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Normal => 'gray',
            self::Alta => 'warning',
            self::Urgente => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Normal => Heroicon::OutlinedMinus,
            self::Alta => Heroicon::OutlinedChevronUp,
            self::Urgente => Heroicon::OutlinedChevronDoubleUp,
        };
    }

    /** Peso na ordenação da fila: maior vem antes. */
    public function peso(): int
    {
        return match ($this) {
            self::Normal => 0,
            self::Alta => 1,
            self::Urgente => 2,
        };
    }
}
