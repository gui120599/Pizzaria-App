<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            // Default true preserva o comportamento atual: todos os produtos
            // vendáveis aparecem na tela de pedidos do garçom/atendente.
            $table->boolean('produto_cardapio_garcom')->default(true)->after('produto_cardapio');
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('produto_cardapio_garcom');
        });
    }
};
