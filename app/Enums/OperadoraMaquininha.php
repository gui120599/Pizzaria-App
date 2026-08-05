<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OperadoraMaquininha: string implements HasLabel
{
    case Stone = 'stone';
    case Cielo = 'cielo';
    case Rede = 'rede';
    case GetNet = 'getnet';
    case Outra = 'outra';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stone => 'Stone',
            self::Cielo => 'Cielo',
            self::Rede => 'Rede',
            self::GetNet => 'GetNet',
            self::Outra => 'Outra',
        };
    }
}
