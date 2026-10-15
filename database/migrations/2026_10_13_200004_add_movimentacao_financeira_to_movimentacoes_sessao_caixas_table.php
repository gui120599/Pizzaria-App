<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retirada/suprimento da sessão criada pelo CaixaPdvBridge a partir de uma
     * movimentação financeira (vale, baixa, transferência). Preenchida = a
     * despesa já existe no financeiro; nenhuma rotina pode gerar lançamento a
     * partir desta linha (evita despesa duplicada).
     */
    public function up(): void
    {
        Schema::table('movimentacoes_sessao_caixas', function (Blueprint $table) {
            $table->foreignId('movimentacao_financeira_id')
                ->nullable()
                ->constrained('movimentacoes_financeiras')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes_sessao_caixas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('movimentacao_financeira_id');
        });
    }
};
