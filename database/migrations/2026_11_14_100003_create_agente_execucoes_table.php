<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Rodadas do agente de compras (registrar_resumo do servidor MCP). Só leitura no painel. */
    public function up(): void
    {
        Schema::create('agente_execucoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'ae_user_fk')->nullOnDelete();
            $table->dateTime('iniciada_em')->nullable();
            $table->dateTime('finalizada_em')->nullable();
            $table->unsignedInteger('pendencias_resolvidas')->default(0);
            $table->unsignedInteger('pendencias_sugeridas')->default(0);
            $table->text('resumo')->nullable();
            $table->timestamps();

            $table->index('finalizada_em', 'ae_finalizada_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agente_execucoes');
    }
};
