<?php

namespace App\Enums;

enum TipoMovimentoCarteiraEnum: string
{
    case CREDITO_COMISSAO = 'credito_comissao';
    case ESTORNO_COMISSAO = 'estorno_comissao';
    case RESGATE_PIX = 'resgate_pix';
    case CONSUMO = 'consumo';
    case ESTORNO_CONSUMO = 'estorno_consumo';
    case AJUSTE = 'ajuste';

    public function label(): string
    {
        return match ($this) {
            self::CREDITO_COMISSAO => 'Comissão creditada',
            self::ESTORNO_COMISSAO => 'Estorno de comissão',
            self::RESGATE_PIX => 'Resgate em Pix',
            self::CONSUMO => 'Consumo em pizza',
            self::ESTORNO_CONSUMO => 'Estorno de consumo',
            self::AJUSTE => 'Ajuste',
        };
    }
}
