<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocao_adicional_ofertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pao_regra_id')
                ->constrained('promocao_adicional_regras')
                ->cascadeOnDelete();
            $table->foreignId('pao_produto_oferta_id')
                ->constrained('produtos')
                ->restrictOnDelete();

            // Quanto o cliente paga a mais pelo produto ofertado.
            $table->decimal('pao_valor_adicional', 10, 2);

            // Teto agregado desta oferta específica (todos os pedidos). Null = ilimitado.
            $table->unsignedInteger('pao_qtd_total')->nullable();
            $table->decimal('pao_qtd_vendida', 10, 2)->default(0);

            $table->timestamps();

            $table->unique(['pao_regra_id', 'pao_produto_oferta_id'], 'unq_promoad_oferta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocao_adicional_ofertas');
    }
};
