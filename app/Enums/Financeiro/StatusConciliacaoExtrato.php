<?php

namespace App\Enums\Financeiro;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatusConciliacaoExtrato: string implements HasColor, HasLabel
{
    case Pendente = 'pendente';
    case Conciliado = 'conciliado';
    case Ambiguo = 'ambiguo';
    case SemCorrespondencia = 'sem_correspondencia';
    case Ignorado = 'ignorado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Conciliado => 'Conciliado',
            self::Ambiguo => 'Ambíguo',
            self::SemCorrespondencia => 'Sem correspondência',
            self::Ignorado => 'Ignorado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendente => 'gray',
            self::Conciliado => 'success',
            self::Ambiguo => 'warning',
            self::SemCorrespondencia => 'danger',
            self::Ignorado => 'gray',
        };
    }
}
