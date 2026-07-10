<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classificação contábil do produto: a que plano de despesas o custo dele
     * pertence (ex.: insumo -> CMV, produto de revenda -> Bebidas para revenda).
     * Usado para ratear a conta a pagar gerada ao confirmar uma compra.
     */
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->foreignId('produto_plano_despesa_id')
                ->nullable()
                ->after('produto_categoria_id')
                ->constrained('planos_despesas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('produto_plano_despesa_id');
        });
    }
};
