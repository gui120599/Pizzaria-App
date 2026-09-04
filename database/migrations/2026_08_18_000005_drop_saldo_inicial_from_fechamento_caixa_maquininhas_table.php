<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_caixa_maquininhas', function (Blueprint $table) {
            // Carryover passa a ter fonte única em sessao_caixa_maquininhas (abertura)
            // — ver FechamentoCaixa::totalPorCategoria(), que consulta lá em vez de
            // duplicar o dado aqui.
            $table->dropColumn('saldo_inicial');
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_caixa_maquininhas', function (Blueprint $table) {
            $table->decimal('saldo_inicial', 12, 2)->nullable();
        });
    }
};
