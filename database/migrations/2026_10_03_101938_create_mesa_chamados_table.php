<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fila de chamados do QR para o garçom: chamar, pedir a conta ou (mesa
        // sem conta aberta) pedir para abrir. Quem atendeu e quando ficam aqui.
        Schema::create('mesa_chamados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mc_mesa_id')->constrained('mesas')->cascadeOnDelete();
            // Null no ABRIR_MESA: ainda não há conta aberta.
            $table->foreignId('mc_sessao_mesa_id')->nullable()->constrained('sessao_mesas')->cascadeOnDelete();
            $table->foreignId('mc_mesa_participante_id')->nullable()->constrained('mesa_participantes')->nullOnDelete();
            $table->string('mc_tipo', 20);
            $table->string('mc_status', 20)->default('PENDENTE');
            $table->string('mc_ip', 45)->nullable();
            $table->foreignId('mc_atendido_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mc_atendido_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['mc_mesa_id', 'mc_status'], 'idx_mesa_chamados_mesa_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesa_chamados');
    }
};
