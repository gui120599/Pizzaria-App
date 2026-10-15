<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Periodicidade de apuração/pagamento do ponto. Nome distinto de App\Enums\Periodicidade (mensal/anual/eventual do plano de despesas).
 */
enum PeriodicidadeRepasse: string implements HasColor, HasLabel
{
    case Semanal = 'semanal';
    case Quinzenal = 'quinzenal';
    case Mensal = 'mensal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Semanal => 'Semanal',
            self::Quinzenal => 'Quinzenal',
            self::Mensal => 'Mensal',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Semanal => 'info',
            self::Quinzenal => 'warning',
            self::Mensal => 'primary',
        };
    }
}
