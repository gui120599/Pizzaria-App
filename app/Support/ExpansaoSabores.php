<?php

namespace App\Support;

use App\Filament\Resources\Categorias\Schemas\QuantidadesSaboresRepeater;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Relatórios por produto enxergando cada sabor de uma pizza de vários sabores.
 *
 * A pizza é UMA linha em itens_vendas (produto = 1º sabor, sabores em JSON).
 * Para somar por produto, cada sabor vira uma linha virtual com quantidade,
 * receita e custo proporcionais ao seu percentual congelado. Itens comuns
 * passam inalterados.
 *
 * Usa JSON_EXTRACT por posição (UNION ALL de 0 até o teto de sabores) em vez
 * de JSON_TABLE para funcionar também em MySQL 5.7.
 */
class ExpansaoSabores
{
    /**
     * Mesmas colunas de itens_vendas usadas pelos widgets do dashboard —
     * use como tabela derivada com alias "itens_vendas".
     */
    public static function itensVenda(): Builder
    {
        $query = DB::table('itens_vendas')
            ->whereNull('item_venda_sabores')
            ->select([
                'id',
                'item_venda_venda_id',
                'item_venda_produto_id',
                'item_venda_quantidade',
                'item_venda_valor',
                'item_venda_custo_unitario',
                'item_venda_status',
            ]);

        for ($posicao = 0; $posicao < QuantidadesSaboresRepeater::MAX_SABORES; $posicao++) {
            $percentual = "CAST(JSON_EXTRACT(item_venda_sabores, '$[{$posicao}].percentual') AS DECIMAL(10,4)) / 100";

            $query->unionAll(
                DB::table('itens_vendas')
                    ->whereNotNull('item_venda_sabores')
                    ->whereRaw('JSON_LENGTH(item_venda_sabores) > ?', [$posicao])
                    ->select([
                        'id',
                        'item_venda_venda_id',
                        DB::raw("CAST(JSON_EXTRACT(item_venda_sabores, '$[{$posicao}].produto_id') AS UNSIGNED) as item_venda_produto_id"),
                        DB::raw("item_venda_quantidade * {$percentual} as item_venda_quantidade"),
                        DB::raw("item_venda_valor * {$percentual} as item_venda_valor"),
                        // Custo por unidade do SABOR (congelado no JSON pelo
                        // ItensVendaObserver); qtd do sabor × este custo = a
                        // parte do sabor no custo da pizza.
                        DB::raw("COALESCE(CAST(JSON_EXTRACT(item_venda_sabores, '$[{$posicao}].custo_unitario') AS DECIMAL(20,8)), item_venda_custo_unitario) as item_venda_custo_unitario"),
                        'item_venda_status',
                    ])
            );
        }

        return $query;
    }
}
