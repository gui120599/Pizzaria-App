<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apuração diária — tabela DERIVADA (upsert pelo ApuracaoService), pode
     * ser recalculada enquanto o período estiver aberto. Por isso sem soft
     * delete. Tempo sempre em minutos inteiros.
     */
    public function up(): void
    {
        Schema::create('ponto_dias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->date('data_referencia');
            $table->foreignId('ponto_periodo_id')->nullable()->constrained('ponto_periodos')->nullOnDelete();
            // App\Enums\Ponto\SituacaoDia
            $table->string('situacao', 30);
            $table->unsignedInteger('minutos_previstos')->default(0);
            $table->unsignedInteger('minutos_trabalhados')->default(0);
            $table->unsignedInteger('minutos_intervalo')->default(0);
            // Minutos de relógio entre 22h e 05h.
            $table->unsignedInteger('minutos_noturnos')->default(0);
            // Acréscimo da hora noturna reduzida (52'30"): já somado em minutos_trabalhados.
            $table->unsignedInteger('minutos_acrescimo_noturno')->default(0);
            $table->unsignedInteger('minutos_extra_50')->default(0);
            $table->unsignedInteger('minutos_extra_100')->default(0);
            $table->unsignedInteger('minutos_atraso')->default(0);
            $table->unsignedInteger('minutos_falta')->default(0);
            $table->unsignedInteger('minutos_abonados')->default(0);
            $table->unsignedSmallInteger('entregas_qtd')->default(0);
            // Lista de códigos: marcacao_faltante, intervalo_insuficiente, …
            $table->json('inconsistencias')->nullable();
            $table->dateTime('apurado_em')->nullable();
            $table->timestamps();

            $table->unique(['colaborador_id', 'data_referencia']);
            $table->index('data_referencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ponto_dias');
    }
};
