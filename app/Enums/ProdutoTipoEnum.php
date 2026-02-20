<?php

namespace App\Enums;

enum ProdutoTipoEnum: string
{
    case PRODUZIDO = 'produzido';
    case REVENDA = 'revenda';
    case INSUMO = 'insumo';
    case CONSUMO_INTERNO = 'consumo_interno';

    public function label(): string
    {
        return match($this) {
            self::PRODUZIDO => 'Produto Produzido',
            self::REVENDA => 'Produto Revenda',
            self::INSUMO => 'Insumo',
            self::CONSUMO_INTERNO => 'Consumo Interno',
        };
    }

    public function controlaEstoqueDireto(): bool
    {
        return match($this) {
            self::PRODUZIDO => false,
            default => true,
        };
    }
}
