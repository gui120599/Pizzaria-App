<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocoes_adicionais', function (Blueprint $table) {
            $table->id();
            $table->string('promoad_nome');
            $table->string('promoad_descricao')->nullable();
            $table->boolean('promoad_ativa')->default(true);
            $table->boolean('promoad_recorrente')->default(false);

            // Janela única. Nullable porque promoção recorrente não tem fim fixo
            // (mesma convenção de promocoes_relampago).
            $table->dateTime('promoad_inicio');
            $table->dateTime('promoad_fim')->nullable();

            // Dias da semana em que a recorrência vale (0=domingo...6=sábado,
            // convenção do Carbon::dayOfWeek). Vazio/null = todos os dias.
            $table->json('promoad_dias_semana')->nullable();
            // Janela diária. Null nos dois = dia inteiro. hora_fim < hora_inicio
            // é interpretado como janela que atravessa a meia-noite.
            $table->time('promoad_hora_inicio')->nullable();
            $table->time('promoad_hora_fim')->nullable();
            // Última data em que a recorrência vale. Null = para sempre.
            $table->date('promoad_data_final_recorrencia')->nullable();
            // Reservado para quando existir contador de campanha a resetar;
            // hoje o saldo vive por oferta (promocao_adicional_ofertas).
            $table->dateTime('promoad_ultimo_reset_em')->nullable();

            // Quando true, o override de preço do gatilho também vale se ele
            // entrar num combo de sabores (meia a meia/três sabores), desde
            // que todos os sabores escolhidos compartilhem a mesma campanha —
            // ver PrecificadorService::ratearCombo()/regraAdicionalDoCombo().
            $table->boolean('promoad_aplica_fracionado')->default(false);

            $table->unsignedInteger('promoad_ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['promoad_ativa', 'promoad_inicio', 'promoad_fim'], 'idx_promoad_vigencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocoes_adicionais');
    }
};
