<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O prazo deixa de ser D+N (dias corridos) e passa a ser a opção do
        // aplicativo da maquininha (PrazoRecebimentoMaquininhaEnum).
        // D+0 vira "na hora"; D+1 ou mais vira "próximo dia útil".
        Schema::table('maquininha_taxas', function (Blueprint $table) {
            $table->string('mt_prazo_recebimento', 20)->nullable()->after('mt_percentual');
        });

        DB::table('maquininha_taxas')->where('mt_prazo_recebimento_dias', 0)->update(['mt_prazo_recebimento' => 'na_hora']);
        DB::table('maquininha_taxas')->where('mt_prazo_recebimento_dias', '>', 0)->update(['mt_prazo_recebimento' => 'proximo_dia_util']);

        Schema::table('maquininha_taxas', function (Blueprint $table) {
            $table->dropColumn('mt_prazo_recebimento_dias');
        });
    }

    public function down(): void
    {
        Schema::table('maquininha_taxas', function (Blueprint $table) {
            $table->unsignedSmallInteger('mt_prazo_recebimento_dias')->nullable()->after('mt_percentual');
        });

        DB::table('maquininha_taxas')->whereIn('mt_prazo_recebimento', ['na_hora', 'mesmo_dia'])->update(['mt_prazo_recebimento_dias' => 0]);
        DB::table('maquininha_taxas')->where('mt_prazo_recebimento', 'proximo_dia_util')->update(['mt_prazo_recebimento_dias' => 1]);

        Schema::table('maquininha_taxas', function (Blueprint $table) {
            $table->dropColumn('mt_prazo_recebimento');
        });
    }
};
