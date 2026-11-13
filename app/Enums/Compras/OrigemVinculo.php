<?php

namespace App\Enums\Compras;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Como um vínculo do de-para (fornecedor_produtos) foi criado. */
enum OrigemVinculo: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case Agente = 'agente';
    case Ean = 'ean';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Agente => 'Agente',
            self::Ean => 'EAN',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Agente => 'info',
            self::Ean => 'success',
        };
    }
}
