<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trilha de auditoria do módulo de ponto (colaborador, remuneração,
     * escala, período, repasse, adiantamento), gravada por observers. Mesmo
     * molde de checklist_historicos: imutável, só created_at.
     */
    public function up(): void
    {
        Schema::create('ponto_historicos', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditavel');
            $table->string('evento', 40);
            // Campos alterados: {"campo": {"de": …, "para": …}} ou contexto livre do evento.
            $table->json('dados')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ponto_historicos');
    }
};
