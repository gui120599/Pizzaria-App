<?php

namespace App\Enums;

enum OrigemCupomEnum: string
{
    case INTERNO = 'interno';
    case INFLUENCIADOR = 'influenciador';

    public function label(): string
    {
        return match ($this) {
            self::INTERNO => 'Interno',
            self::INFLUENCIADOR => 'Influenciador',
        };
    }
}
