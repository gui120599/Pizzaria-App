<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linha da venda em que o item do pedido entrou. Com o lançamento item a
 * item no PDV, retirar um item precisa abater a linha certa — a busca por
 * produto (ItensVenda::correspondenteAoItemPedido) fica só para os itens
 * lançados antes desta coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->foreignId('item_pedido_item_venda_id')
                ->nullable()
                ->after('item_pedido_venda_id')
                ->constrained('itens_vendas', indexName: 'itens_pedidos_item_venda_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropForeign('itens_pedidos_item_venda_fk');
            $table->dropColumn('item_pedido_item_venda_id');
        });
    }
};
