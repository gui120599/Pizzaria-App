<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoria dos cadastros do módulo de cozinha (setores, rotas, fichas,
     * dispositivos) e da liberação de produção sem abertura. Mesmo padrão de
     * checklist_historicos: polimórfica, imutável, só created_at.
     */
    public function up(): void
    {
        Schema::create('cozinha_historicos', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditavel');
            $table->string('evento', 40);
            // {"campo": {"de": …, "para": …}} ou contexto livre do evento.
            $table->json('dados')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cozinha_historicos');
    }
};
