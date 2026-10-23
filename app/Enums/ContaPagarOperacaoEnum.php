<?php

namespace App\Enums;

/** Se uma operação fiscal de entrada gera título a pagar. */
enum ContaPagarOperacaoEnum: string
{
    case SIM = 'sim';
    case NAO = 'nao';
    case SE_HOUVER_COBRANCA = 'se_houver_cobranca';
    case A_DEFINIR = 'a_definir';

    public function label(): string
    {
        return match ($this) {
            self::SIM => 'Sim',
            self::NAO => 'Não',
            self::SE_HOUVER_COBRANCA => 'Somente se houver cobrança',
            self::A_DEFINIR => 'Depende da operação',
        };
    }
}
