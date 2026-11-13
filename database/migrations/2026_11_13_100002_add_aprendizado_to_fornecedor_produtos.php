<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** De-para: EAN, último custo e quem/como o vínculo foi confirmado. */
    public function up(): void
    {
        Schema::table('fornecedor_produtos', function (Blueprint $table) {
            $table->string('fp_ean', 14)->nullable();
            $table->decimal('fp_ultimo_custo_unitario', 12, 4)->nullable();
            // App\Enums\Compras\OrigemVinculo (null = vínculo anterior à automação).
            $table->string('fp_origem', 10)->nullable();
            $table->foreignId('fp_confirmado_por')->nullable()
                ->constrained('users', 'id', 'fp_confirmado_por_fk')->nullOnDelete();

            $table->index('fp_ean', 'fp_ean_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fornecedor_produtos', function (Blueprint $table) {
            $table->dropForeign('fp_confirmado_por_fk');
            $table->dropIndex('fp_ean_idx');
            $table->dropColumn(['fp_ean', 'fp_ultimo_custo_unitario', 'fp_origem', 'fp_confirmado_por']);
        });
    }
};
