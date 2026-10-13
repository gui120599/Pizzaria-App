<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_modelo_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_modelo_id')->constrained('checklist_modelos')->cascadeOnDelete();
            $table->foreignId('checklist_modelo_secao_id')
                ->nullable()
                ->constrained('checklist_modelo_secoes', 'id', 'ck_mod_itens_secao_fk')
                ->nullOnDelete();
            $table->string('titulo', 200);
            $table->text('instrucao')->nullable();
            // App\Enums\Checklist\TipoResposta
            $table->string('tipo_resposta', 30);
            // Só para "selecao": [{"valor":"ok","rotulo":"OK","conforme":true}, …]
            $table->json('opcoes')->nullable();
            // Só para "sim_nao": "sim" | "nao" (null = qualquer resposta é conforme).
            $table->string('resposta_esperada', 10)->nullable();
            $table->decimal('valor_minimo', 10, 2)->nullable();
            $table->decimal('valor_maximo', 10, 2)->nullable();
            $table->string('unidade', 10)->nullable();
            $table->boolean('obrigatorio')->default(true);
            $table->boolean('critico')->default(false);
            // App\Enums\Checklist\ExigenciaFoto
            $table->string('exige_foto', 20)->default('nunca');
            $table->boolean('exige_observacao_nao_conforme')->default(true);
            // Reservado para perguntas condicionais (backlog) — não usado ainda.
            $table->json('condicao')->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['checklist_modelo_id', 'ordem'], 'ck_mod_itens_modelo_ordem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_modelo_itens');
    }
};
