<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            // MEDIA preserva o comportamento atual do PrecificadorService.
            $table->enum('categoria_regra_preco_sabores', ['MEDIA', 'MAIOR'])->default('MEDIA')->after('categoria_herda_sabores');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('categoria_regra_preco_sabores');
        });
    }
};
