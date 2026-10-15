<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Quem originou o ajuste.
 */
enum OrigemAjuste: string implements HasColor, HasIcon, HasLabel
{
    case Colaborador = 'colaborador';
    case Gestor = 'gestor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Colaborador => 'Solicitação do colaborador',
            self::Gestor => 'Correção do gestor',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Colaborador => 'info',
            self::Gestor => 'warning',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Colaborador => Heroicon::OutlinedUser,
            self::Gestor => Heroicon::OutlinedPencilSquare,
        };
    }
}
