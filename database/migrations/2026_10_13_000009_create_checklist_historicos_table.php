<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trilha de auditoria do módulo de checklists, gravada pelos observers de
     * ChecklistModelo, ChecklistExecucao, ChecklistExecucaoItem e
     * ChecklistNaoConformidade. Só created_at e sem soft delete: o registro é
     * imutável (mesmo padrão de historico_status_pedidos).
     */
    public function up(): void
    {
        Schema::create('checklist_historicos', function (Blueprint $table) {
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
        Schema::dropIfExists('checklist_historicos');
    }
};
