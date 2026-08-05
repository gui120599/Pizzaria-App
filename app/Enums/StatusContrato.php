<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatusContrato: string implements HasColor, HasLabel
{
    case Ativo = 'ativo';
    case Suspenso = 'suspenso';
    case Encerrado = 'encerrado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ativo => 'Ativo',
            self::Suspenso => 'Suspenso',
            self::Encerrado => 'Encerrado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ativo => 'success',
            self::Suspenso => 'warning',
            self::Encerrado => 'gray',
        };
    }
}
