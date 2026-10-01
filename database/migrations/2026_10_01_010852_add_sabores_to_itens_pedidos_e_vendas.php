<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pizza de vários sabores passa a ser UMA linha de item, com os sabores
     * congelados em JSON (ver App\Models\Concerns\TemSabores). null = item comum.
     *
     * O ledger promocao_consumos deixa de ser 1 linha por item: uma pizza
     * meia a meia promocional consome dois produtos da promoção numa só linha
     * de item, então a unicidade passa a ser (item, produto da promoção). A
     * única nova é criada antes de remover a antiga porque a FK de
     * consumo_item_pedido_id precisa de um índice começando por essa coluna.
     */
    public function up(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->json('item_pedido_sabores')->nullable()->after('item_pedido_observacao');
        });

        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->json('item_venda_sabores')->nullable()->after('item_venda_observacao');
        });

        Schema::table('promocao_consumos', function (Blueprint $table) {
            $table->unique(['consumo_item_pedido_id', 'consumo_promocao_produto_id'], 'unq_consumo_item_produto');
        });

        Schema::table('promocao_consumos', function (Blueprint $table) {
            $table->dropUnique('unq_consumo_item');
        });
    }

    public function down(): void
    {
        Schema::table('promocao_consumos', function (Blueprint $table) {
            $table->unique('consumo_item_pedido_id', 'unq_consumo_item');
        });

        Schema::table('promocao_consumos', function (Blueprint $table) {
            $table->dropUnique('unq_consumo_item_produto');
        });

        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->dropColumn('item_venda_sabores');
        });

        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropColumn('item_pedido_sabores');
        });
    }
};
