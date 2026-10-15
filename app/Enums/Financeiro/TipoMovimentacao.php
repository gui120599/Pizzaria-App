<?php

namespace App\Enums\Financeiro;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Direção da movimentação; o valor é sempre positivo.
 */
enum TipoMovimentacao: string implements HasColor, HasIcon, HasLabel
{
    case Entrada = 'entrada';
    case Saida = 'saida';

    public function getLabel(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::Saida => 'Saída',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Entrada => 'success',
            self::Saida => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Entrada => Heroicon::OutlinedArrowDownCircle,
            self::Saida => Heroicon::OutlinedArrowUpCircle,
        };
    }

    public function inverso(): self
    {
        return $this === self::Entrada ? self::Saida : self::Entrada;
    }

    /** +1 para entrada, -1 para saída. */
    public function sinal(): int
    {
        return $this === self::Entrada ? 1 : -1;
    }
}
