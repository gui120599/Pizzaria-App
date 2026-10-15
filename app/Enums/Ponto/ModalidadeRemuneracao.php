<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Como o colaborador é remunerado numa vigência.
 */
enum ModalidadeRemuneracao: string implements HasColor, HasLabel
{
    case Mensal = 'mensal';
    case PorHora = 'por_hora';
    case PorDiaria = 'por_diaria';
    case PorTurno = 'por_turno';
    case SomenteEntregas = 'somente_entregas';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mensal => 'Salário mensal',
            self::PorHora => 'Por hora',
            self::PorDiaria => 'Por diária',
            self::PorTurno => 'Por turno',
            self::SomenteEntregas => 'Somente entregas',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Mensal => 'primary',
            self::PorHora => 'info',
            self::PorDiaria => 'warning',
            self::PorTurno => 'warning',
            self::SomenteEntregas => 'success',
        };
    }
}
