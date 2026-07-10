<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rateio do lançamento (a pagar) entre planos de despesa: 1 lançamento -> N despesas.
     * Uma compra pode misturar insumos (CMV) e produtos de revenda (outro plano), então o
     * título é dividido por plano. `plano_despesa_id` nullable guarda o remanescente de
     * produtos sem classificação, mantendo a soma das linhas igual ao valor do lançamento.
     */
    public function up(): void
    {
        Schema::create('lancamento_despesas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lancamento_id')
                ->constrained('lancamentos')
                ->cascadeOnDelete();
            $table->foreignId('plano_despesa_id')
                ->nullable()
                ->constrained('planos_despesas')
                ->restrictOnDelete();
            $table->decimal('valor', 12, 2);
            // Snapshot do comportamento (fixo/variável) do plano no momento do rateio, para a DRE.
            $table->string('comportamento')->nullable();
            $table->timestamps();

            $table->index(['lancamento_id', 'plano_despesa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lancamento_despesas');
    }
};
