<?php

namespace App\Enums\Compras;

use Filament\Support\Contracts\HasLabel;

/**
 * Chave do de-para (fornecedor_produtos.fp_codigo_fornecedor): o cProd da
 * NF-e ou, na compra avulsa, a descrição normalizada (NormalizadorDescricao).
 */
enum TipoChaveVinculo: string implements HasLabel
{
    case Codigo = 'codigo';
    case Descricao = 'descricao';

    public function getLabel(): string
    {
        return match ($this) {
            self::Codigo => 'Código da nota',
            self::Descricao => 'Descrição (compra avulsa)',
        };
    }
}
