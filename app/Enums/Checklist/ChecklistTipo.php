<?php

namespace App\Enums\Checklist;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ChecklistTipo: string implements HasColor, HasIcon, HasLabel
{
    case Abertura = 'abertura';
    case Fechamento = 'fechamento';
    case Turno = 'turno';
    case Limpeza = 'limpeza';
    case Outro = 'outro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Abertura => 'Abertura',
            self::Fechamento => 'Fechamento',
            self::Turno => 'Turno',
            self::Limpeza => 'Limpeza',
            self::Outro => 'Outro',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Abertura => 'success',
            self::Fechamento => 'info',
            self::Turno => 'warning',
            self::Limpeza => 'primary',
            self::Outro => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Abertura => Heroicon::OutlinedSun,
            self::Fechamento => Heroicon::OutlinedMoon,
            self::Turno => Heroicon::OutlinedArrowsRightLeft,
            self::Limpeza => Heroicon::OutlinedSparkles,
            self::Outro => Heroicon::OutlinedClipboardDocumentList,
        };
    }
}
