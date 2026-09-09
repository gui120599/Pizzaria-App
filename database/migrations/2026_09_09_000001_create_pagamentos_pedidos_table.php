<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forma de pagamento COMBINADA no atendimento do pedido — dado
     * informativo/planejado, nunca uma cobrança real. Por isso não tem FK
     * pra vendas/sessoes_caixa/movimentacoes_sessao_caixa: o dinheiro só
     * "entra" de fato via Venda (OperarVenda), como já acontece hoje. Serve
     * de base pronta pra uma iniciativa futura (ainda não desenhada) de
     * finalizar a venda a partir do webhook da Stone Connect.
     */
    public function up(): void
    {
        Schema::create('pagamentos_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pg_pedido_pedido_id')->constrained('pedidos', 'id')->cascadeOnDelete();
            $table->foreignId('pg_pedido_opcaopagamento_id')->nullable()->constrained('opcoes_pagamentos', 'id')->nullOnDelete();
            $table->string('pg_pedido_opcaopagamento_nome')->nullable();
            $table->decimal('pg_pedido_valor', 10, 2)->default(0);
            $table->decimal('pg_pedido_valor_troco_para', 10, 2)->nullable();
            $table->unsignedTinyInteger('pg_pedido_ordem')->default(0);
            $table->timestamps();

            $table->index(['pg_pedido_pedido_id', 'pg_pedido_ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos_pedidos');
    }
};
