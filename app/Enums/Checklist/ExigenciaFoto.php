<?php

namespace App\Enums\Checklist;

use Filament\Support\Contracts\HasLabel;

enum ExigenciaFoto: string implements HasLabel
{
    case Nunca = 'nunca';
    case Sempre = 'sempre';
    case SeNaoConforme = 'se_nao_conforme';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nunca => 'Nunca',
            self::Sempre => 'Sempre',
            self::SeNaoConforme => 'Só se não conforme',
        };
    }
}
