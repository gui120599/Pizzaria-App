<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger simples de crédito de um fornecedor (Prestador) com a pizzaria,
     * originado por devolução de compra. Saldo disponível = soma de `valor`
     * onde `aplicado_em_lancamento_id` é nulo. Não mexe na aritmética de
     * Lancamento (que assume valores sempre positivos) — o crédito é
     * abatido manualmente da(s) parcela(s) geradas na próxima compra desse
     * fornecedor (ver CompraService::gerarContaPagar).
     */
    public function up(): void
    {
        Schema::create('prestador_creditos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestador_id')
                ->constrained('prestadores')
                ->restrictOnDelete();
            $table->string('origem_tipo');
            $table->unsignedBigInteger('origem_id');
            $table->decimal('valor', 12, 2);
            $table->foreignId('aplicado_em_lancamento_id')
                ->nullable()
                ->constrained('lancamentos')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['prestador_id', 'aplicado_em_lancamento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestador_creditos');
    }
};
