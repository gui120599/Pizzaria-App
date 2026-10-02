<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizacoes_gerente', function (Blueprint $table) {
            $table->id();
            $table->string('autorizacao_acao', 40);
            $table->foreignId('autorizacao_solicitante_id')->constrained('users');
            $table->foreignId('autorizacao_autorizador_id')->constrained('users');
            $table->string('autorizacao_auditavel_type');
            $table->unsignedBigInteger('autorizacao_auditavel_id');
            $table->string('autorizacao_motivo')->nullable();
            $table->json('autorizacao_dados')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['autorizacao_auditavel_type', 'autorizacao_auditavel_id'], 'autorizacoes_gerente_auditavel_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizacoes_gerente');
    }
};
