<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 2 (analytics): congela o custo do item no momento da venda.
 *
 * itens_vendas não guardava o custo, então o CMV usava o custo médio ATUAL
 * do produto (impreciso e quebra retroativamente quando o custo muda).
 * Agora cada item guarda item_venda_custo_unitario (snapshot), preenchido
 * pelo ItensVendaObserver na criação. O CMV passa a ser fiel ao período.
 *
 * Backfill: linhas históricas recebem o custo médio atual como aproximação
 * (única base disponível para o passado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->decimal('item_venda_custo_unitario', 12, 4)
                ->default(0)
                ->after('item_venda_valor_unitario');
        });

        // Backfill histórico com o custo médio atual do produto.
        DB::statement('
            UPDATE itens_vendas iv
            JOIN produtos p ON p.id = iv.item_venda_produto_id
            SET iv.item_venda_custo_unitario = p.produto_custo_medio
        ');
    }

    public function down(): void
    {
        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->dropColumn('item_venda_custo_unitario');
        });
    }
};
