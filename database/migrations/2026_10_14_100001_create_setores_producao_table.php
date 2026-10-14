<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Setores de produção da cozinha (Pizzaria, Cozinha, Bar…) e o passe
     * (Expedição). Cada tablet vê só os itens dos seus setores. Substitui
     * `linhas_producao`, que continua existindo (dados copiados na migration
     * seguinte).
     */
    public function up(): void
    {
        Schema::create('setores_producao', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 60)->unique();
            $table->string('slug', 60)->unique();
            // App\Enums\Cozinha\TipoSetorProducao. No máximo um `expedicao` ativo (validado no service).
            $table->string('tipo', 20)->default('producao');
            $table->string('cor', 20)->default('#f97316');
            $table->string('icone', 60)->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            // Timer do card: amarelo a partir de alerta, vermelho a partir de alvo.
            $table->unsignedSmallInteger('tempo_alerta_min')->default(10);
            $table->unsignedSmallInteger('tempo_alvo_min')->default(15);
            // true: 1 card por unidade (pizza); false: 1 card com a quantidade (6 latas).
            $table->boolean('explode_unidades')->default(true);
            $table->boolean('permite_pular_inicio')->default(false);
            $table->boolean('exige_operador')->default(true);
            $table->boolean('exibir_ficha_automatica')->default(false);
            $table->boolean('som_novo_item')->default(true);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ativo', 'ordem'], 'setor_prod_ativo_ordem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setores_producao');
    }
};
