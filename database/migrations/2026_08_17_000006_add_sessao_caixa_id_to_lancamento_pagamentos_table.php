<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Só preenchido quando o pagamento é registrado a partir do PDV (aba Pendentes
     * do OperarVenda) — é o que faz o recebimento de fiado entrar de verdade na
     * conferência do Fechamento de Caixa da sessão aberta no momento do recebimento
     * (que pode ser diferente da sessão em que a venda originalmente foi finalizada).
     */
    public function up(): void
    {
        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->foreignId('sessao_caixa_id')
                ->nullable()
                ->after('lancamento_id')
                ->constrained('sessao_caixas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sessao_caixa_id');
        });
    }
};
