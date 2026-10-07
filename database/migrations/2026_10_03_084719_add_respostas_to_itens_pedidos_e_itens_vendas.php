<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Respostas das perguntas do item congeladas no momento do pedido
        // (pergunta, opções e valor de cada uma): mudar o cadastro depois não
        // altera pedidos já lançados. Null = item sem perguntas.
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->json('item_pedido_respostas')->nullable()->after('item_pedido_sabores');
        });

        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->json('item_venda_respostas')->nullable()->after('item_venda_sabores');
        });
    }

    public function down(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropColumn('item_pedido_respostas');
        });

        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->dropColumn('item_venda_respostas');
        });
    }
};
