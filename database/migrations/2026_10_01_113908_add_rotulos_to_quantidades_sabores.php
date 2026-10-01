<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Descrição de cada posição de sabor ("MEIA", "½", livre ou vazia), exibida
     * antes do nome do sabor nas telas e impressões. null = padrão por extenso
     * (QuantidadeSabor::rotulos()), que é o comportamento anterior.
     */
    public function up(): void
    {
        Schema::table('quantidades_sabores', function (Blueprint $table) {
            $table->json('quantidade_sabor_rotulos')->nullable()->after('quantidade_sabor_percentuais');
        });
    }

    public function down(): void
    {
        Schema::table('quantidades_sabores', function (Blueprint $table) {
            $table->dropColumn('quantidade_sabor_rotulos');
        });
    }
};
