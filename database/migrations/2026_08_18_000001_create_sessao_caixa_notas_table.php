<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessao_caixa_notas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sessao_caixa_id')
                ->constrained('sessao_caixas')
                ->cascadeOnDelete();
            $table->foreignId('nota_moeda_id')
                ->constrained('notas_moedas')
                ->restrictOnDelete();
            $table->unsignedInteger('quantidade')->default(0);
            // Snapshot de quantidade x notas_moedas.valor no momento do save.
            $table->decimal('valor_total', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['sessao_caixa_id', 'nota_moeda_id'], 'scx_notas_sessao_nota_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessao_caixa_notas');
    }
};
