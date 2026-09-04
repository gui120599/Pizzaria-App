<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessao_caixa_maquininhas', function (Blueprint $table) {
            // Carryover de cada maquininha quebrado por categoria (era um único
            // saldo_inicial agregado) — permite ao fechamento abater o valor certo
            // de cada categoria, não só do total geral.
            $table->decimal('valor_debito', 12, 2)->default(0)->after('maquininha_id');
            $table->decimal('valor_credito', 12, 2)->default(0)->after('valor_debito');
            $table->decimal('valor_pix', 12, 2)->default(0)->after('valor_credito');
        });

        Schema::table('sessao_caixa_maquininhas', function (Blueprint $table) {
            $table->dropColumn('saldo_inicial');
        });
    }

    public function down(): void
    {
        Schema::table('sessao_caixa_maquininhas', function (Blueprint $table) {
            $table->decimal('saldo_inicial', 12, 2)->default(0);
        });

        Schema::table('sessao_caixa_maquininhas', function (Blueprint $table) {
            $table->dropColumn(['valor_debito', 'valor_credito', 'valor_pix']);
        });
    }
};
