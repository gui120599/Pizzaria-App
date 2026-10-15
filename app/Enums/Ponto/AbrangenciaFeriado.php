<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Abrangência do feriado.
 */
enum AbrangenciaFeriado: string implements HasColor, HasLabel
{
    case Nacional = 'nacional';
    case Estadual = 'estadual';
    case Municipal = 'municipal';
    case Facultativo = 'facultativo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nacional => 'Nacional',
            self::Estadual => 'Estadual',
            self::Municipal => 'Municipal',
            self::Facultativo => 'Ponto facultativo',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Nacional => 'primary',
            self::Estadual => 'info',
            self::Municipal => 'warning',
            self::Facultativo => 'gray',
        };
    }
}
