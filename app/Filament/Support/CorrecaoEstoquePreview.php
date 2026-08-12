<?php

namespace App\Filament\Support;

use App\Support\CustoUnitarioFormatter;
use Illuminate\Support\HtmlString;

/**
 * Formata o resultado de CorrecaoEstoqueService::simular() como prévia
 * legível dentro do modal de correção — reaproveitado pela action da Compra
 * (ItensRelationManager) e pela do Produto (EditProduto), que mostram o
 * mesmo tipo de resultado.
 */
final class CorrecaoEstoquePreview
{
    public static function resumo(array $resultado): HtmlString
    {
        $linhas = [
            sprintf(
                'Saldo do produto: %s → %s',
                number_format($resultado['produto_saldo_atual'], 3, ',', '.'),
                number_format($resultado['produto_saldo_novo'], 3, ',', '.'),
            ),
            sprintf(
                'Custo médio: %s → %s',
                CustoUnitarioFormatter::formatar($resultado['produto_custo_medio_atual']),
                CustoUnitarioFormatter::formatar($resultado['produto_custo_medio_novo']),
            ),
        ];

        $qtdVendas = $resultado['vendas_afetadas']->count();
        if ($qtdVendas > 0) {
            $linhas[] = "{$qtdVendas} venda(s) terão o custo (CMV) corrigido junto.";
        }

        $qtdIndiretas = $resultado['vendas_indiretas_ignoradas']->count();
        if ($qtdIndiretas > 0) {
            $linhas[] = "Atenção: {$qtdIndiretas} venda(s) consumiram este produto como insumo de ficha técnica — o custo dessas vendas pode ficar desatualizado e precisa ser revisado manualmente.";
        }

        if ($resultado['saldo_ficaria_negativo'] ?? false) {
            $linhas[] = 'Atenção: o saldo do produto fica negativo em algum momento da linha do tempo — normalize com um Balanço físico depois de aplicar.';
        }

        return new HtmlString(implode('<br>', array_map('e', $linhas)));
    }
}
