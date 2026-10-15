<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Ciclo de vida do ajuste. Correção do gestor já nasce aprovada.
 */
enum StatusAjuste: string implements HasColor, HasIcon, HasLabel
{
    case Pendente = 'pendente';
    case Aprovado = 'aprovado';
    case Rejeitado = 'rejeitado';
    case Desfeito = 'desfeito';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Aprovado => 'Aprovado',
            self::Rejeitado => 'Rejeitado',
            self::Desfeito => 'Desfeito',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendente => 'warning',
            self::Aprovado => 'success',
            self::Rejeitado => 'danger',
            self::Desfeito => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pendente => Heroicon::OutlinedClock,
            self::Aprovado => Heroicon::OutlinedCheckCircle,
            self::Rejeitado => Heroicon::OutlinedXCircle,
            self::Desfeito => Heroicon::OutlinedArrowUturnLeft,
        };
    }
}
