<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->boolean('categoria_permite_sabores')->default(false)->after('categoria_cardapio');
            $table->unsignedTinyInteger('categoria_max_sabores')->default(2)->after('categoria_permite_sabores');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn(['categoria_permite_sabores', 'categoria_max_sabores']);
        });
    }
};
