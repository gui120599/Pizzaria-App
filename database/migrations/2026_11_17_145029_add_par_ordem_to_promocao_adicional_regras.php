<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ordem dos produtos gatilho de cada campanha, definida arrastando as regras
 * no formulário; o cardápio exibe os gatilhos nessa ordem. As regras que já
 * existem ficam na ordem em que foram criadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocao_adicional_regras', function (Blueprint $table) {
            $table->unsignedInteger('par_ordem')->default(0)->after('par_qtd_maxima_por_pedido');
        });

        $ordemPorCampanha = [];

        DB::table('promocao_adicional_regras')->orderBy('id')->get(['id', 'par_promocao_id'])
            ->each(function (object $regra) use (&$ordemPorCampanha) {
                $ordem = $ordemPorCampanha[$regra->par_promocao_id] = ($ordemPorCampanha[$regra->par_promocao_id] ?? 0) + 1;

                DB::table('promocao_adicional_regras')->where('id', $regra->id)->update(['par_ordem' => $ordem]);
            });
    }

    public function down(): void
    {
        Schema::table('promocao_adicional_regras', function (Blueprint $table) {
            $table->dropColumn('par_ordem');
        });
    }
};
