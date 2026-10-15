<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Situação apurada de um dia operacional.
 */
enum SituacaoDia: string implements HasColor, HasIcon, HasLabel
{
    case Normal = 'normal';
    case Folga = 'folga';
    case Feriado = 'feriado';
    case Falta = 'falta';
    case Incompleto = 'incompleto';
    case Inconsistente = 'inconsistente';
    case Abonado = 'abonado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Folga => 'Folga',
            self::Feriado => 'Feriado',
            self::Falta => 'Falta',
            self::Incompleto => 'Incompleto',
            self::Inconsistente => 'Inconsistente',
            self::Abonado => 'Abonado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Normal => 'success',
            self::Folga => 'gray',
            self::Feriado => 'info',
            self::Falta => 'danger',
            self::Incompleto => 'warning',
            self::Inconsistente => 'warning',
            self::Abonado => 'info',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Normal => Heroicon::OutlinedCheckCircle,
            self::Folga => Heroicon::OutlinedSun,
            self::Feriado => Heroicon::OutlinedFlag,
            self::Falta => Heroicon::OutlinedXCircle,
            self::Incompleto => Heroicon::OutlinedExclamationTriangle,
            self::Inconsistente => Heroicon::OutlinedExclamationCircle,
            self::Abonado => Heroicon::OutlinedShieldCheck,
        };
    }
}
