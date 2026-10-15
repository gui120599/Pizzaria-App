<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Por onde a marcação entrou.
 */
enum OrigemMarcacao: string implements HasColor, HasIcon, HasLabel
{
    case App = 'app';
    case Quiosque = 'quiosque';
    case ManualGestor = 'manual_gestor';
    case Importacao = 'importacao';

    public function getLabel(): string
    {
        return match ($this) {
            self::App => 'Celular (app)',
            self::Quiosque => 'Quiosque',
            self::ManualGestor => 'Manual (gestor)',
            self::Importacao => 'Importação',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::App => 'primary',
            self::Quiosque => 'info',
            self::ManualGestor => 'warning',
            self::Importacao => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::App => Heroicon::OutlinedDevicePhoneMobile,
            self::Quiosque => Heroicon::OutlinedComputerDesktop,
            self::ManualGestor => Heroicon::OutlinedPencilSquare,
            self::Importacao => Heroicon::OutlinedArrowUpTray,
        };
    }
}
