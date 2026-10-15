<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Ciclo de vida do período de apuração.
 */
enum StatusPeriodo: string implements HasColor, HasIcon, HasLabel
{
    case Aberto = 'aberto';
    case EmConferencia = 'em_conferencia';
    case Fechado = 'fechado';
    case Pago = 'pago';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aberto => 'Aberto',
            self::EmConferencia => 'Em conferência',
            self::Fechado => 'Fechado',
            self::Pago => 'Pago',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aberto => 'info',
            self::EmConferencia => 'warning',
            self::Fechado => 'primary',
            self::Pago => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Aberto => Heroicon::OutlinedLockOpen,
            self::EmConferencia => Heroicon::OutlinedMagnifyingGlass,
            self::Fechado => Heroicon::OutlinedLockClosed,
            self::Pago => Heroicon::OutlinedBanknotes,
        };
    }

    /** Marcações e ajustes do período não podem mais mudar a apuração. */
    public function travado(): bool
    {
        return $this !== self::Aberto;
    }
}
