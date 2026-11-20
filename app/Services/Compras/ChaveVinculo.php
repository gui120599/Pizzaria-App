<?php

namespace App\Services\Compras;

use App\Enums\Compras\TipoChaveVinculo;
use App\Models\Compra;
use App\Models\CompraItem;

/**
 * De que chave o de-para de um item é gravado: o código do fornecedor (cProd
 * da NF-e) quando existe; na compra avulsa, que não tem código, a descrição
 * normalizada. Item de compra manual sem código não gera de-para (como antes).
 */
class ChaveVinculo
{
    /** @return array{tipo: TipoChaveVinculo, codigo: string}|null */
    public static function doItem(CompraItem $item, ?Compra $compra = null): ?array
    {
        if (filled($item->ci_codigo_fornecedor)) {
            return ['tipo' => TipoChaveVinculo::Codigo, 'codigo' => (string) $item->ci_codigo_fornecedor];
        }

        $compra ??= $item->compra;
        $descricao = NormalizadorDescricao::normalizar($item->ci_descricao_fornecedor);

        if ($compra?->ehAvulsa() && $descricao !== '') {
            return ['tipo' => TipoChaveVinculo::Descricao, 'codigo' => $descricao];
        }

        return null;
    }

    /**
     * Atributos de busca do vínculo (firstOrNew com withTrashed: o unique vale
     * também para linhas excluídas).
     *
     * @return array<string, int|string>|null
     */
    public static function atributos(CompraItem $item, ?Compra $compra = null): ?array
    {
        $compra ??= $item->compra;
        $chave = self::doItem($item, $compra);

        if ($chave === null || $compra?->compra_prestador_id === null) {
            return null;
        }

        return [
            'fp_prestador_id' => $compra->compra_prestador_id,
            'fp_tipo_chave' => $chave['tipo']->value,
            'fp_codigo_fornecedor' => $chave['codigo'],
        ];
    }
}
