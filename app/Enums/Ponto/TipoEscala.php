<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de escala; "livre" não tem previsto (freelancer).
 */
enum TipoEscala: string implements HasColor, HasLabel
{
    case Fixa = 'fixa';
    case Revezamento = 'revezamento';
    case PorExcecao = 'por_excecao';
    case Livre = 'livre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixa => 'Fixa',
            self::Revezamento => 'Revezamento',
            self::PorExcecao => 'Por exceção',
            self::Livre => 'Livre (sem previsto)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Fixa => 'primary',
            self::Revezamento => 'info',
            self::PorExcecao => 'warning',
            self::Livre => 'gray',
        };
    }

    /** Escala sem jornada prevista: não gera falta, atraso nem extra. */
    public function semPrevisto(): bool
    {
        return $this === self::Livre;
    }
}
