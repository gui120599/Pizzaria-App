<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Modo de preparo: passos filtrados pelo setor do tablet (null = todos os setores). */
    public function up(): void
    {
        Schema::create('ficha_tecnica_passos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ficha_tecnica_id')->constrained('fichas_tecnicas')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('titulo', 120);
            $table->text('descricao')->nullable();
            $table->string('foto')->nullable();
            $table->foreignId('setor_producao_id')->nullable()->constrained('setores_producao')->nullOnDelete();
            // Gera botão de timer no tablet.
            $table->unsignedInteger('tempo_segundos')->nullable();
            $table->string('temperatura_alvo', 20)->nullable();
            $table->boolean('ponto_critico')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ficha_tecnica_id', 'ordem'], 'ficha_passo_ficha_ordem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ficha_tecnica_passos');
    }
};
