<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessao_caixa_maquininhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sessao_caixa_id')
                ->constrained('sessao_caixas')
                ->cascadeOnDelete();
            $table->foreignId('maquininha_id')
                ->constrained('maquininhas')
                ->restrictOnDelete();
            // Valor já acumulado na maquininha na hora da abertura (não pertence a
            // este turno) — flui para fechamento_caixa_maquininhas.saldo_inicial e é
            // abatido lá do total apurado (ver FechamentoCaixa::totalMaquininhasLiquido).
            $table->decimal('saldo_inicial', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['sessao_caixa_id', 'maquininha_id'], 'scx_maquininhas_sessao_maquininha_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessao_caixa_maquininhas');
    }
};
