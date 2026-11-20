<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantidade limitada da Promoção Combo (Fase 4, Q7): total de combos que a
 * promoção concede (por ocorrência, quando recorrente). Os combos usados não
 * ficam num contador: são contados no histórico (pedido_combo_aplicacoes),
 * que o motor refaz a cada mudança do pedido e que o cancelamento devolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes_combo', function (Blueprint $table) {
            $table->unsignedInteger('promocombo_qtd_total')->nullable()->after('promocombo_limite_por_pedido');
        });
    }

    public function down(): void
    {
        Schema::table('promocoes_combo', function (Blueprint $table) {
            $table->dropColumn('promocombo_qtd_total');
        });
    }
};
