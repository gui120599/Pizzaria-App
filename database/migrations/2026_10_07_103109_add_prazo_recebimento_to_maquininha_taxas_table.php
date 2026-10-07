<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dias corridos até a adquirente depositar (D+N). Nulo = não informado.
        // Usado na previsão de recebimento do Relatório de Fechamento de Caixa.
        Schema::table('maquininha_taxas', function (Blueprint $table) {
            $table->unsignedSmallInteger('mt_prazo_recebimento_dias')->nullable()->after('mt_percentual');
        });
    }

    public function down(): void
    {
        Schema::table('maquininha_taxas', function (Blueprint $table) {
            $table->dropColumn('mt_prazo_recebimento_dias');
        });
    }
};
