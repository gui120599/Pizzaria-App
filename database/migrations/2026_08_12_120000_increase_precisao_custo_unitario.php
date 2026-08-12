<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->decimal('produto_preco_custo', 16, 8)->nullable()->change();
            $table->decimal('produto_custo_medio', 16, 8)->default(0)->change();
        });

        Schema::table('compra_itens', function (Blueprint $table) {
            $table->decimal('ci_custo_unitario_compra', 16, 8)->default(0)->change();
        });

        Schema::table('estoque_lotes', function (Blueprint $table) {
            $table->decimal('lote_custo_unitario', 16, 8)->default(0)->change();
        });

        Schema::table('movimentacao_produtos', function (Blueprint $table) {
            $table->decimal('mov_custo_unitario', 16, 8)->default(0)->change();
        });

        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->decimal('item_venda_custo_unitario', 16, 8)->default(0)->change();
        });

        Schema::table('produto_precos_historicos', function (Blueprint $table) {
            $table->decimal('valor_antigo_custo', 16, 8)->nullable()->change();
            $table->decimal('valor_novo_custo', 16, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->decimal('produto_preco_custo', 10, 2)->nullable()->change();
            $table->decimal('produto_custo_medio', 12, 4)->default(0)->change();
        });

        Schema::table('compra_itens', function (Blueprint $table) {
            $table->decimal('ci_custo_unitario_compra', 12, 4)->default(0)->change();
        });

        Schema::table('estoque_lotes', function (Blueprint $table) {
            $table->decimal('lote_custo_unitario', 12, 4)->default(0)->change();
        });

        Schema::table('movimentacao_produtos', function (Blueprint $table) {
            $table->decimal('mov_custo_unitario', 12, 4)->default(0)->change();
        });

        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->decimal('item_venda_custo_unitario', 12, 4)->default(0)->change();
        });

        Schema::table('produto_precos_historicos', function (Blueprint $table) {
            $table->decimal('valor_antigo_custo', 10, 2)->nullable()->change();
            $table->decimal('valor_novo_custo', 10, 2)->nullable()->change();
        });
    }
};
