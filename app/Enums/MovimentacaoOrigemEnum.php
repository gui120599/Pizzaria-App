<?php

namespace App\Enums;

enum MovimentacaoOrigemEnum: string
{
    case COMPRA = 'compra';
    case PRODUCAO = 'producao';
    case VENDA = 'venda';
    case AJUSTE = 'ajuste';
    case PERDA = 'perda';
    case CONSUMO_INTERNO = 'consumo_interno';
    case TRANSFERENCIA = 'transferencia';
    case INVENTARIO = 'inventario';
    case BALANCO = 'balanco';

    public function label(): string
    {
        return match ($this) {
            self::COMPRA => 'Compra / Entrada NF',
            self::PRODUCAO => 'Produção',
            self::VENDA => 'Venda',
            self::AJUSTE => 'Ajuste manual',
            self::PERDA => 'Perda / Quebra',
            self::CONSUMO_INTERNO => 'Consumo interno',
            self::TRANSFERENCIA => 'Transferência',
            self::INVENTARIO => 'Inventário',
            self::BALANCO => 'Balanço físico',
        };
    }
}
