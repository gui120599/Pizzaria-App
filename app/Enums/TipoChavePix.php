<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipo da chave PIX (colaborador, prestador e conta financeira).
 */
enum TipoChavePix: string implements HasLabel
{
    case Cpf = 'cpf';
    case Cnpj = 'cnpj';
    case Email = 'email';
    case Telefone = 'telefone';
    case Aleatoria = 'aleatoria';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cpf => 'CPF',
            self::Cnpj => 'CNPJ',
            self::Email => 'E-mail',
            self::Telefone => 'Telefone',
            self::Aleatoria => 'Chave aleatória',
        };
    }
}
