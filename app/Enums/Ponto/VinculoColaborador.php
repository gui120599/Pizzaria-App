<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Vínculo de trabalho do colaborador (define plano de despesa e regras do repasse).
 */
enum VinculoColaborador: string implements HasColor, HasIcon, HasLabel
{
    case Clt = 'clt';
    case Diarista = 'diarista';
    case Freelancer = 'freelancer';
    case MeiPj = 'mei_pj';
    case Estagio = 'estagio';

    public function getLabel(): string
    {
        return match ($this) {
            self::Clt => 'CLT',
            self::Diarista => 'Diarista',
            self::Freelancer => 'Freelancer',
            self::MeiPj => 'MEI / PJ',
            self::Estagio => 'Estágio',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Clt => 'info',
            self::Diarista => 'warning',
            self::Freelancer => 'gray',
            self::MeiPj => 'primary',
            self::Estagio => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Clt => Heroicon::OutlinedIdentification,
            self::Diarista => Heroicon::OutlinedCalendarDays,
            self::Freelancer => Heroicon::OutlinedBolt,
            self::MeiPj => Heroicon::OutlinedBuildingOffice,
            self::Estagio => Heroicon::OutlinedAcademicCap,
        };
    }

    /** CLT e estágio viram Prestador "funcionário"; os demais, "autônomo". */
    public function ehEmpregado(): bool
    {
        return in_array($this, [self::Clt, self::Estagio], true);
    }
}
