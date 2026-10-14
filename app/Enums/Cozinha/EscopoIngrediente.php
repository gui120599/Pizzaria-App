<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Como a linha da ficha se comporta numa pizza de vários sabores: a base
 * aparece uma vez; cobertura e finalização são multiplicadas pela fração de
 * cada sabor.
 */
enum EscopoIngrediente: string implements HasColor, HasLabel
{
    case Base = 'base';
    case Cobertura = 'cobertura';
    case Finalizacao = 'finalizacao';

    public function getLabel(): string
    {
        return match ($this) {
            self::Base => 'Base',
            self::Cobertura => 'Cobertura',
            self::Finalizacao => 'Finalização',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Base => 'gray',
            self::Cobertura => 'warning',
            self::Finalizacao => 'success',
        };
    }

    public function multiplicaPelaFracao(): bool
    {
        return $this !== self::Base;
    }
}
