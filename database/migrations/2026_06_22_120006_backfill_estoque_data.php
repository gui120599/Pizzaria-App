<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Unidade de estoque assume a unidade comercial quando não definida.
        DB::table('produtos')
            ->whereNull('produto_unidade_estoque')
            ->update(['produto_unidade_estoque' => DB::raw('produto_unidade_comercial')]);

        // Custo médio inicial = custo de compra atual cadastrado.
        DB::table('produtos')
            ->where('produto_custo_medio', 0)
            ->whereNotNull('produto_preco_custo')
            ->update(['produto_custo_medio' => DB::raw('produto_preco_custo')]);

        // Saldo de estoque inicial recomputado das movimentações existentes.
        $saldos = DB::table('movimentacao_produtos')
            ->selectRaw("mov_produto_id, SUM(CASE WHEN mov_tipo = 'ENTRADA' THEN mov_quantidade ELSE -mov_quantidade END) AS saldo")
            ->whereNull('deleted_at')
            ->groupBy('mov_produto_id')
            ->get();

        foreach ($saldos as $linha) {
            DB::table('produtos')
                ->where('id', $linha->mov_produto_id)
                ->update(['produto_saldo_estoque' => $linha->saldo ?? 0]);
        }

        // Movimentações antigas: data e origem retroativas.
        DB::table('movimentacao_produtos')
            ->whereNull('mov_data')
            ->update(['mov_data' => DB::raw('created_at')]);

        DB::table('movimentacao_produtos')
            ->whereNull('mov_origem')
            ->update(['mov_origem' => DB::raw("CASE WHEN mov_venda_id IS NOT NULL THEN 'venda' ELSE 'ajuste' END")]);

        // Custos históricos das movimentações ficam em 0 — não há base para
        // reconstruir o custo médio retroativo. O CMV passa a ser apurado a
        // partir das novas movimentações registradas pelo EstoqueService.
    }

    public function down(): void
    {
        // Migration apenas de dados — sem reversão.
    }
};
