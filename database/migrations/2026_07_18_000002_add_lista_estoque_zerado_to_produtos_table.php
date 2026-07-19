<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            // Default true: a maioria dos produtos com controle de estoque ligado
            // já está com saldo zerado (o saldo só passou a acumular recentemente),
            // então default false tiraria quase o cardápio inteiro do ar.
            $table->boolean('produto_lista_estoque_zerado')->default(true)->after('produto_modo_controle_estoque');
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('produto_lista_estoque_zerado');
        });
    }
};
