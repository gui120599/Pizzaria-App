<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->unsignedSmallInteger('categoria_ordem')->default(0)->after('categoria_nome');
        });

        Schema::table('produtos', function (Blueprint $table) {
            $table->unsignedSmallInteger('produto_ordem')->default(0)->after('produto_descricao');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('categoria_ordem');
        });

        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('produto_ordem');
        });
    }
};
