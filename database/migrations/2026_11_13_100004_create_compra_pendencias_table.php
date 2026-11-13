<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** O que a automação de compras não conseguiu decidir por regra. */
    public function up(): void
    {
        Schema::create('compra_pendencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras', 'id', 'cp_compra_fk')->cascadeOnDelete();
            $table->foreignId('compra_item_id')->nullable()
                ->constrained('compra_itens', 'id', 'cp_compra_item_fk')->cascadeOnDelete();
            // App\Enums\Compras\TipoPendenciaCompra
            $table->string('tipo', 40);
            // App\Enums\Compras\StatusPendencia
            $table->string('status', 20)->default('aberta');
            $table->json('dados')->nullable();
            $table->json('sugestao')->nullable();
            $table->decimal('confianca', 4, 3)->nullable();
            $table->text('justificativa')->nullable();
            $table->json('resolucao')->nullable();
            $table->foreignId('resolvida_por')->nullable()
                ->constrained('users', 'id', 'cp_resolvida_por_fk')->nullOnDelete();
            $table->dateTime('resolvida_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'tipo'], 'cp_status_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_pendencias');
    }
};
