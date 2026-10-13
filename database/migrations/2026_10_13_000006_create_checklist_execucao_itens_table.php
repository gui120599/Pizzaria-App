<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_execucao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_execucao_id')
                ->constrained('checklist_execucoes', 'id', 'ck_exec_itens_execucao_fk')
                ->cascadeOnDelete();
            // nullOnDelete: o histórico sobrevive à exclusão do item no modelo.
            $table->foreignId('checklist_modelo_item_id')
                ->nullable()
                ->constrained('checklist_modelo_itens', 'id', 'ck_exec_itens_modelo_item_fk')
                ->nullOnDelete();
            // Snapshot do item do modelo.
            $table->string('secao_titulo', 120)->nullable();
            $table->string('titulo', 200);
            $table->text('instrucao')->nullable();
            $table->string('tipo_resposta', 30);
            // opcoes, resposta_esperada, valor_minimo, valor_maximo, unidade,
            // obrigatorio, critico, exige_foto, exige_observacao_nao_conforme.
            $table->json('config');
            // Respostas — a coluna usada depende do tipo (TipoResposta::colunaValor()).
            $table->boolean('valor_booleano')->nullable();
            $table->decimal('valor_numero', 10, 2)->nullable();
            $table->text('valor_texto')->nullable();
            $table->string('valor_opcao', 50)->nullable();
            // null = não respondido / não avaliável.
            $table->boolean('conforme')->nullable();
            $table->text('observacao')->nullable();
            $table->foreignId('respondido_por')
                ->nullable()
                ->constrained('users', 'id', 'ck_exec_itens_respondido_por_fk')
                ->nullOnDelete();
            $table->dateTime('respondido_em')->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['checklist_execucao_id', 'ordem'], 'ck_exec_itens_execucao_ordem_idx');
            // Relatório "itens que mais falham".
            $table->index(['checklist_modelo_item_id', 'conforme'], 'ck_exec_itens_modelo_item_conforme_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_execucao_itens');
    }
};
