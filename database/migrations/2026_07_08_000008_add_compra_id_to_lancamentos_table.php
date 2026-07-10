<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo do título a pagar com a compra que o originou (rastreabilidade).
     * Unique garante idempotência: uma compra gera no máximo uma conta a pagar.
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('compra_id')
                ->nullable()
                ->after('tipo')
                ->constrained('compras')
                ->nullOnDelete();

            $table->unique('compra_id');
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropUnique(['compra_id']);
            $table->dropConstrainedForeignId('compra_id');
        });
    }
};
