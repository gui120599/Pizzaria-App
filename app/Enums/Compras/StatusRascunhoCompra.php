<?php

namespace App\Enums\Compras;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatusRascunhoCompra: string implements HasColor, HasLabel
{
    case Aberto = 'aberto';
    case Confirmado = 'confirmado';
    case Expirado = 'expirado';
    case Descartado = 'descartado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aberto => 'Aberto',
            self::Confirmado => 'Confirmado',
            self::Expirado => 'Expirado',
            self::Descartado => 'Descartado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aberto => 'warning',
            self::Confirmado => 'success',
            self::Expirado, self::Descartado => 'gray',
        };
    }
}
