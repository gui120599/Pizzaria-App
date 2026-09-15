<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            // Marca o item como a "oferta" (ex.: brotinho) nascida de uma regra
            // de promoção adicional.
            $table->foreignId('item_pedido_promocao_adicional_regra_id')
                ->nullable()
                ->after('item_pedido_promocao_id')
                ->constrained('promocao_adicional_regras')
                ->nullOnDelete();

            // Auto-relacionamento: na linha da oferta, aponta para o id da linha
            // do gatilho que a originou (a pizza). Permite cascata de
            // cancelamento/estorno e auditoria sem campos extras.
            $table->foreignId('item_pedido_origem_id')
                ->nullable()
                ->after('item_pedido_promocao_adicional_regra_id')
                ->constrained('itens_pedidos')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropForeign(['item_pedido_promocao_adicional_regra_id']);
            $table->dropForeign(['item_pedido_origem_id']);
            $table->dropColumn(['item_pedido_promocao_adicional_regra_id', 'item_pedido_origem_id']);
        });
    }
};
