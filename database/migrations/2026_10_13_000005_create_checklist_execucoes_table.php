<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_execucoes', function (Blueprint $table) {
            $table->id();
            // restrict: modelo com execução só sai por soft delete/desativação.
            $table->foreignId('checklist_modelo_id')->constrained('checklist_modelos')->restrictOnDelete();
            // Snapshot do cabeçalho do modelo no momento da geração.
            $table->unsignedInteger('modelo_versao');
            $table->string('modelo_nome', 120);
            $table->string('tipo', 30);
            $table->string('setor', 30);
            // Dia operacional (ver config checklists.virada_dia): fechamento à 00:40 pertence ao dia anterior.
            $table->date('data_referencia')->index();
            // App\Enums\Checklist\ExecucaoStatus
            $table->string('status', 30)->index();
            // "{modelo_id}-{Y-m-d}" quando o modelo não permite várias execuções
            // no dia; null quando permite. O unique garante uma por modelo+dia
            // mesmo sob concorrência (MySQL não tem índice unique parcial).
            $table->string('chave_unica', 40)->nullable()->unique();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('iniciado_em')->nullable();
            $table->foreignId('concluido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('concluido_em')->nullable();
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('aprovado_em')->nullable();
            $table->text('motivo_reprovacao')->nullable();
            $table->text('observacao_geral')->nullable();
            $table->unsignedSmallInteger('total_itens')->default(0);
            $table->unsignedSmallInteger('itens_respondidos')->default(0);
            $table->unsignedSmallInteger('itens_nao_conformes')->default(0);
            $table->decimal('percentual_conformidade', 5, 2)->nullable();
            // Sessão de caixa aberta de quem iniciou, se houver.
            $table->foreignId('sessao_caixa_id')->nullable()->constrained('sessao_caixas')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['checklist_modelo_id', 'data_referencia'], 'ck_exec_modelo_data_idx');
            $table->index(['data_referencia', 'status'], 'ck_exec_data_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_execucoes');
    }
};
