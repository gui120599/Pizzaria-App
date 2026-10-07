<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pedido do QR da mesa com este produto (ou produto desta categoria,
        // ex.: bebidas alcoólicas) passa pela aprovação do garçom.
        Schema::table('produtos', function (Blueprint $table) {
            $table->boolean('produto_requer_aprovacao_mesa')->default(false)->after('produto_cardapio_garcom');
        });

        Schema::table('categorias', function (Blueprint $table) {
            $table->boolean('categoria_requer_aprovacao_mesa')->default(false)->after('categoria_cardapio_garcom');
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('produto_requer_aprovacao_mesa');
        });

        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('categoria_requer_aprovacao_mesa');
        });
    }
};
