<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promoção Combo: N itens participantes juntos por um preço fechado
     * (ex.: 2 pizzas grandes por R$ 87,99). Ver docs/planos/promocoes-combo-inteligentes.md.
     */
    public function up(): void
    {
        Schema::create('promocoes_combo', function (Blueprint $table) {
            $table->id();
            $table->string('promocombo_nome');
            $table->string('promocombo_descricao')->nullable();
            $table->boolean('promocombo_ativa')->default(true);
            $table->string('promocombo_tipo_beneficio', 20)->default('PRECO_FIXO');
            $table->unsignedTinyInteger('promocombo_quantidade')->default(2);
            $table->decimal('promocombo_preco', 10, 2);
            $table->boolean('promocombo_recorrente')->default(false);
            $table->dateTime('promocombo_inicio')->nullable();
            $table->dateTime('promocombo_fim')->nullable();
            $table->json('promocombo_dias_semana')->nullable();
            $table->time('promocombo_hora_inicio')->nullable();
            $table->time('promocombo_hora_fim')->nullable();
            $table->date('promocombo_data_final_recorrencia')->nullable();
            $table->dateTime('promocombo_ultimo_reset_em')->nullable();
            $table->json('promocombo_opcoes_entrega')->nullable();
            $table->boolean('promocombo_aplica_fracionado')->default(true);
            $table->boolean('promocombo_cumula_relampago')->default(true);
            $table->boolean('promocombo_cumula_promo_adicional')->default(true);
            $table->boolean('promocombo_sugerir')->default(true);
            $table->unsignedInteger('promocombo_limite_por_pedido')->nullable();
            $table->unsignedInteger('promocombo_prioridade')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocoes_combo');
    }
};
