<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoria das correções de lançamento feitas pelo Relatório de Sessão de
     * Caixa (App\Services\CorrecaoPagamentoService): quem trocou o quê, de →
     * para. Os ids de pagamento não têm FK — o pagamento pode ser removido
     * depois pelo PDV e a trilha deve ficar.
     */
    public function up(): void
    {
        Schema::create('correcoes_pagamento', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');
            $table->foreignId('sessao_caixa_id')->nullable()->constrained('sessao_caixas')->nullOnDelete();
            $table->foreignId('venda_id')->nullable()->constrained('vendas')->nullOnDelete();
            $table->unsignedBigInteger('pagamento_venda_id')->nullable()->index();
            $table->unsignedBigInteger('lancamento_pagamento_id')->nullable()->index();
            $table->json('antes');
            $table->json('depois');
            $table->text('justificativa')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correcoes_pagamento');
    }
};
