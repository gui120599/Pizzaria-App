<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentacoes_sessao_caixas', function (Blueprint $table) {
            // Preenchido nos movimentos ENTRADA de abertura (usa App\Enums\FormaPagamento)
            // pra FechamentoCaixaService::calcularEsperado() somar o saldo de abertura
            // por categoria junto com vendas e recebimentos de fiado.
            $table->string('mov_forma_pagamento')->nullable()->after('mov_tipo');
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes_sessao_caixas', function (Blueprint $table) {
            $table->dropColumn('mov_forma_pagamento');
        });
    }
};
