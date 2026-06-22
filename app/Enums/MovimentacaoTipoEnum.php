<?php

namespace App\Enums;

enum MovimentacaoTipoEnum: string
{
    case ENTRADA = 'ENTRADA';
    case SAIDA = 'SAIDA';

    public function label(): string
    {
        return match ($this) {
            self::ENTRADA => 'Entrada',
            self::SAIDA => 'Saída',
        };
    }

    /** Sinal aplicado ao saldo: +1 para entrada, -1 para saída. */
    public function sinal(): int
    {
        return $this === self::ENTRADA ? 1 : -1;
    }

    public function cor(): string
    {
        return $this === self::ENTRADA ? 'success' : 'danger';
    }
}
