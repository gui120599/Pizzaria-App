<?php

namespace App\Enums;

enum ProdutoTipoEnum: string
{
    case PRODUZIDO = 'produzido';
    case REVENDA = 'revenda';
    case INSUMO = 'insumo';
    case INSUMO_PRODUZIDO = 'insumo_produzido';
    case CONSUMO_INTERNO = 'consumo_interno';

    public function label(): string
    {
        return match ($this) {
            self::PRODUZIDO => 'Produto Produzido',
            self::REVENDA => 'Produto Revenda',
            self::INSUMO => 'Insumo',
            self::INSUMO_PRODUZIDO => 'Insumo Produzido',
            self::CONSUMO_INTERNO => 'Consumo Interno',
        };
    }

    /** Falso para itens cujo estoque é derivado da própria ficha técnica (não têm saldo lançado diretamente por compra). */
    public function controlaEstoqueDireto(): bool
    {
        return match ($this) {
            self::PRODUZIDO, self::INSUMO_PRODUZIDO => false,
            default => true,
        };
    }
}
