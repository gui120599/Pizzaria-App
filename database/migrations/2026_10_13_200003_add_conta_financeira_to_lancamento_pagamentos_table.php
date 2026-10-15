<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conta de onde saiu (pagar) ou para onde foi (receber) o pagamento.
     * Nula nos pagamentos anteriores a este módulo e nas exceções sem dinheiro
     * de verdade (compensação, importação da sessão de caixa). A movimentação
     * gerada aponta para o pagamento (movimentacoes_financeiras.lancamento_pagamento_id).
     */
    public function up(): void
    {
        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->foreignId('conta_financeira_id')
                ->nullable()
                ->after('sessao_caixa_id')
                ->constrained('contas_financeiras')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conta_financeira_id');
        });
    }
};
