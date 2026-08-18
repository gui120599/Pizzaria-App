<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cabeçalho de uma devolução de produtos ao fornecedor de uma compra já
     * confirmada (ex.: pedido cancelado/devolvido). Não altera a Compra
     * original (histórico fiscal/estoque já confirmado) — é um registro à
     * parte, com trilha própria de itens (compra_devolucao_itens) e do
     * crédito gerado ao fornecedor (prestador_creditos).
     */
    public function up(): void
    {
        Schema::create('compra_devolucoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')
                ->constrained('compras')
                ->restrictOnDelete();
            $table->text('motivo')->nullable();
            $table->decimal('valor_total', 12, 2);
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_devolucoes');
    }
};
