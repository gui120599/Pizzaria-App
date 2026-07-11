<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes_relampago', function (Blueprint $table) {
            // Quando true, ignora promocao_fim: a vigência passa a ser
            // recalculada a cada dia a partir de dias_semana + hora_inicio/fim.
            $table->boolean('promocao_recorrente')->default(false)->after('promocao_ativa');

            // Dias da semana em que a recorrência vale (0=domingo...6=sábado,
            // convenção do Carbon::dayOfWeek). Vazio/null = todos os dias.
            $table->json('promocao_dias_semana')->nullable()->after('promocao_fim');

            // Janela diária. Null nos dois = dia inteiro (a partir de promocao_inicio).
            // hora_fim < hora_inicio é interpretado como janela que atravessa a
            // meia-noite (ex.: 22:00–02:00).
            $table->time('promocao_hora_inicio')->nullable()->after('promocao_dias_semana');
            $table->time('promocao_hora_fim')->nullable()->after('promocao_hora_inicio');

            // Última data em que a recorrência vale. Null = para sempre.
            $table->date('promocao_data_final_recorrencia')->nullable()->after('promocao_hora_fim');

            // Marca quando o contador foi zerado pela última vez (início da
            // ocorrência mais recente). Evita resetar mais de uma vez por dia e
            // serve de corte para a reconciliação (soma só os consumos desde aqui).
            $table->dateTime('promocao_ultimo_reset_em')->nullable()->after('promocao_data_final_recorrencia');

            // Promoção recorrente sem data final não tem "fim" fixo.
            $table->dateTime('promocao_fim')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('promocoes_relampago', function (Blueprint $table) {
            $table->dropColumn([
                'promocao_recorrente',
                'promocao_dias_semana',
                'promocao_hora_inicio',
                'promocao_hora_fim',
                'promocao_data_final_recorrencia',
                'promocao_ultimo_reset_em',
            ]);

            $table->dateTime('promocao_fim')->nullable(false)->change();
        });
    }
};
