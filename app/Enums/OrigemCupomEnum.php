<?php

namespace App\Enums;

enum OrigemCupomEnum: string
{
    case INTERNO = 'interno';
    case INFLUENCIADOR = 'influenciador';
    case CREDITO_COMISSAO = 'credito_comissao';

    public function label(): string
    {
        return match ($this) {
            self::INTERNO => 'Interno',
            self::INFLUENCIADOR => 'Influenciador',
            self::CREDITO_COMISSAO => 'Crédito de Comissão',
        };
    }
}
