<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            // Marca o item como nascido de uma promoção relâmpago. É o que permite
            // reconciliar o contador a partir dos itens e estornar no cancelamento.
            // Também congela o preço: recalcularValores() não reprecifica item promocional.
            $table->foreignId('item_pedido_promocao_id')
                ->nullable()
                ->after('item_pedido_produto_id')
                ->constrained('promocoes_relampago')
                ->nullOnDelete();

            // Desconto por unidade no momento da venda. Sem ele, reprecificar um
            // item promocional depois de alterar a quantidade dividiria o desconto
            // antigo pela quantidade nova.
            $table->decimal('item_pedido_desconto_unitario', 10, 2)
                ->nullable()
                ->after('item_pedido_desconto');
        });
    }

    public function down(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropForeign(['item_pedido_promocao_id']);
            $table->dropColumn(['item_pedido_promocao_id', 'item_pedido_desconto_unitario']);
        });
    }
};
