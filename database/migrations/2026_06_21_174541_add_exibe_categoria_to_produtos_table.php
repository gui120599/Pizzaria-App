<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->boolean('produto_exibe_categoria')->default(false)->after('produto_descricao');
            $table->string('produto_preposicao')->nullable()->after('produto_exibe_categoria');
        });

        // Preserva o comportamento anterior: produtos produzidos exibiam o
        // nome da categoria no cardápio. Mantém o prefixo ligado para eles.
        DB::table('produtos')
            ->where('produto_tipo', 'produzido')
            ->update(['produto_exibe_categoria' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn(['produto_exibe_categoria', 'produto_preposicao']);
        });
    }
};
