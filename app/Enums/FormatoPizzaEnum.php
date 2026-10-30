<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Formato da pizza montada com vários sabores (categorias.categoria_formato_pizza).
 * Só muda o desenho da montagem: a quadrada é dividida em faixas.
 */
enum FormatoPizzaEnum: string implements HasLabel
{
    case REDONDA = 'REDONDA';
    case QUADRADA = 'QUADRADA';

    public function getLabel(): string
    {
        return match ($this) {
            self::REDONDA => 'Redonda',
            self::QUADRADA => 'Quadrada',
        };
    }
}
