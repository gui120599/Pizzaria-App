<?php

namespace App\Enums;

enum StatusInfluenciadorEnum: string
{
    case PENDENTE = 'pendente';
    case ATIVO = 'ativo';
    case INATIVO = 'inativo';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::ATIVO => 'Ativo',
            self::INATIVO => 'Inativo',
        };
    }
}
