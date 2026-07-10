<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Periodicidade: string implements HasLabel
{
    case Mensal = 'mensal';
    case Anual = 'anual';
    case Eventual = 'eventual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mensal => 'Mensal',
            self::Anual => 'Anual',
            self::Eventual => 'Eventual',
        };
    }
}
