<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Tipo de ajuste de ponto. Nenhum altera ponto_marcacoes: a apuração soma inclusões e tira desconsiderações.
 */
enum TipoAjuste: string implements HasColor, HasIcon, HasLabel
{
    case Inclusao = 'inclusao';
    case Desconsideracao = 'desconsideracao';
    case Abono = 'abono';
    case FolgaCompensatoria = 'folga_compensatoria';
    case FaltaJustificada = 'falta_justificada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inclusao => 'Inclusão de marcação',
            self::Desconsideracao => 'Desconsideração de marcação',
            self::Abono => 'Abono',
            self::FolgaCompensatoria => 'Folga compensatória',
            self::FaltaJustificada => 'Falta justificada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Inclusao => 'success',
            self::Desconsideracao => 'danger',
            self::Abono => 'info',
            self::FolgaCompensatoria => 'warning',
            self::FaltaJustificada => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Inclusao => Heroicon::OutlinedPlusCircle,
            self::Desconsideracao => Heroicon::OutlinedMinusCircle,
            self::Abono => Heroicon::OutlinedShieldCheck,
            self::FolgaCompensatoria => Heroicon::OutlinedSun,
            self::FaltaJustificada => Heroicon::OutlinedDocumentCheck,
        };
    }
}
