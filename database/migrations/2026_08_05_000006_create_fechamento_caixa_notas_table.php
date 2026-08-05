<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fechamento_caixa_notas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fechamento_caixa_id')
                ->constrained('fechamentos_caixa')
                ->cascadeOnDelete();
            $table->foreignId('nota_moeda_id')
                ->constrained('notas_moedas')
                ->restrictOnDelete();
            $table->unsignedInteger('quantidade')->default(0);
            // Snapshot de quantidade x notas_moedas.valor no momento do save.
            $table->decimal('valor_total', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['fechamento_caixa_id', 'nota_moeda_id'], 'fcx_notas_fechamento_nota_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_caixa_notas');
    }
};
