<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->unsignedBigInteger('item_pedido_venda_id')->nullable()->after('item_pedido_pedido_id');
            $table->foreign('item_pedido_venda_id')->references('id')->on('vendas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropForeign(['item_pedido_venda_id']);
            $table->dropColumn('item_pedido_venda_id');
        });
    }
};
