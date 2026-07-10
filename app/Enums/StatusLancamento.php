<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatusLancamento: string implements HasColor, HasLabel
{
    case Pendente = 'pendente';
    case Pago = 'pago';
    case Cancelado = 'cancelado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Pago => 'Pago / Recebido',
            self::Cancelado => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendente => 'warning',
            self::Pago => 'success',
            self::Cancelado => 'gray',
        };
    }
}
