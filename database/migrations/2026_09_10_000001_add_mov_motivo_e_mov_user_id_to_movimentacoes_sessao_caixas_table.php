<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentacoes_sessao_caixas', function (Blueprint $table) {
            // Motivo estruturado (App\Enums\MotivoSaidaCaixa) de uma SAIDA ou ENTRADA
            // MANUAL (sangria/suprimento). Fica NULL nos movimentos automáticos (venda,
            // saldo inicial de abertura, estorno Stone) — é o que isola um movimento
            // manual no recomputo do saldo (MovimentacaoCaixaService::recalcularSaldoFinal)
            // e no desconto do "esperado" da conferência (FechamentoCaixaService).
            $table->string('mov_motivo')->nullable()->after('mov_forma_pagamento');

            // Quem registrou a sangria/suprimento — auditoria que hoje não existe.
            $table->foreignId('mov_user_id')->nullable()->after('mov_motivo')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes_sessao_caixas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mov_user_id');
            $table->dropColumn('mov_motivo');
        });
    }
};
