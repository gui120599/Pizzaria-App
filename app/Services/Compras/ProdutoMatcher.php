<?php

namespace App\Services\Compras;

use App\Models\FornecedorProduto;
use App\Models\Produto;
use App\Services\Compras\Dto\ResultadoMatch;

/**
 * Reconhece o produto do estoque de um item de NF-e, na ordem (regra 9 da
 * spec de compras automatizadas):
 * 1. de-para do fornecedor pelo código do produto na nota (cProd);
 * 2. EAN já aprendido no de-para do mesmo fornecedor;
 * 3. EAN do cadastro de produtos.
 *
 * O EAN chega já sem o "SEM GTIN" (NfeXmlParser). Nunca cria nada: quem
 * grava o vínculo é quem usa o resultado.
 */
class ProdutoMatcher
{
    public function identificar(int $prestadorId, ?string $codigoFornecedor, ?string $ean): ?ResultadoMatch
    {
        if (filled($codigoFornecedor)) {
            $vinculo = FornecedorProduto::query()
                ->where('fp_prestador_id', $prestadorId)
                ->where('fp_codigo_fornecedor', $codigoFornecedor)
                ->first();

            if ($vinculo !== null) {
                return new ResultadoMatch((int) $vinculo->fp_produto_id, $this->fator($vinculo), $vinculo, ResultadoMatch::DE_PARA);
            }
        }

        if (blank($ean)) {
            return null;
        }

        $vinculo = FornecedorProduto::query()
            ->where('fp_prestador_id', $prestadorId)
            ->where('fp_ean', $ean)
            ->first();

        if ($vinculo !== null) {
            return new ResultadoMatch((int) $vinculo->fp_produto_id, $this->fator($vinculo), $vinculo, ResultadoMatch::EAN_DE_PARA);
        }

        $produtoId = Produto::query()->where('produto_codigo_EAN', $ean)->value('id');

        return $produtoId !== null
            ? new ResultadoMatch((int) $produtoId, 1.0, null, ResultadoMatch::EAN_PRODUTO)
            : null;
    }

    private function fator(FornecedorProduto $vinculo): float
    {
        return (float) $vinculo->fp_fator_conversao ?: 1.0;
    }
}
