<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pausa temporária de produto (acabou o insumo): guarda quais canais
     * foram desligados para religar só esses na retomada, manual ou no
     * horário de `pausado_ate` (comando produtos:retomar-pausas).
     */
    public function up(): void
    {
        Schema::create('produto_pausas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produto_id')->constrained('produtos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('canais');
            $table->string('motivo')->nullable();
            $table->dateTime('pausado_ate')->nullable();
            $table->dateTime('retomada_em')->nullable();
            $table->foreignId('retomada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['produto_id', 'retomada_em']);
            $table->index(['retomada_em', 'pausado_ate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_pausas');
    }
};
