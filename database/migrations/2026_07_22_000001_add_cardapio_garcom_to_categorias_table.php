<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            // Default true preserva o comportamento atual: hoje a tela de
            // pedidos do garçom/atendente lista todas as categorias com
            // produto vendável, sem nenhum filtro de visibilidade.
            $table->boolean('categoria_cardapio_garcom')->default(true)->after('categoria_cardapio');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('categoria_cardapio_garcom');
        });
    }
};
