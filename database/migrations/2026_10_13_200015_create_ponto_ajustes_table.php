<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajustes de ponto (solicitação do colaborador ou correção direta do
     * gestor). Nunca alteram ponto_marcacoes. `lote_uuid` agrupa os ajustes de
     * uma mesma correção (ex.: corrigir horário = desconsideração + inclusão)
     * para exibir e desfazer juntos. Desfazer muda o status para "desfeito"
     * e registra quem/quando/por quê — nada é apagado.
     */
    public function up(): void
    {
        Schema::create('ponto_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->date('data_referencia');
            // App\Enums\Ponto\TipoAjuste
            $table->string('tipo', 30);
            // Desconsideração: a marcação desconsiderada.
            $table->foreignId('ponto_marcacao_id')->nullable()->constrained('ponto_marcacoes')->restrictOnDelete();
            // Inclusão: o horário incluído.
            $table->dateTime('data_hora')->nullable();
            // Abono: minutos abonados (nulo = dia inteiro).
            $table->unsignedSmallInteger('minutos')->nullable();
            // App\Enums\Ponto\MotivoAjuste
            $table->string('motivo', 30);
            $table->text('justificativa');
            $table->string('anexo_caminho')->nullable();
            // App\Enums\Ponto\StatusAjuste
            $table->string('status', 20)->default('pendente')->index();
            // App\Enums\Ponto\OrigemAjuste
            $table->string('origem', 20)->default('colaborador');
            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decidido_em')->nullable();
            $table->text('motivo_rejeicao')->nullable();
            $table->char('lote_uuid', 36)->nullable()->index();
            $table->dateTime('colaborador_ciente_em')->nullable();
            $table->foreignId('desfeito_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('desfeito_em')->nullable();
            $table->text('justificativa_desfazer')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['colaborador_id', 'data_referencia'], 'ponto_ajuste_colab_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ponto_ajustes');
    }
};
