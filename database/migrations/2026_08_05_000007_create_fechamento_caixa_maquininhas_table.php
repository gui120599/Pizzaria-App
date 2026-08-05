<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fechamento_caixa_maquininhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fechamento_caixa_id')
                ->constrained('fechamentos_caixa')
                ->cascadeOnDelete();
            $table->foreignId('maquininha_id')
                ->constrained('maquininhas')
                ->restrictOnDelete();
            $table->decimal('valor_debito', 12, 2)->default(0);
            $table->decimal('valor_credito', 12, 2)->default(0);
            $table->decimal('valor_pix', 12, 2)->default(0);
            // Valor acumulado que a maquininha ainda não zerou (tipo odômetro) —
            // subtraído só do total geral, ver FechamentoCaixa::totalMaquininhasLiquido.
            $table->decimal('saldo_inicial', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['fechamento_caixa_id', 'maquininha_id'], 'fcx_maquininhas_fechamento_maquininha_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_caixa_maquininhas');
    }
};
