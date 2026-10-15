<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entrada/saída de dinheiro real numa conta. Imutável (só created_at, sem
     * soft delete): correção é por estorno, uma movimentação inversa que aponta
     * para a original em `estorno_de_id` (unique: uma movimentação só é
     * estornada uma vez).
     *
     * `adiantamento_id` nasce sem constraint: a tabela adiantamentos é criada
     * depois (a FK entra em 2026_10_13_200019).
     */
    public function up(): void
    {
        Schema::create('movimentacoes_financeiras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_financeira_id')->constrained('contas_financeiras')->restrictOnDelete();
            // App\Enums\Financeiro\TipoMovimentacao
            $table->string('tipo', 10);
            // App\Enums\Financeiro\NaturezaMovimentacao
            $table->string('natureza', 30);
            // Sempre positivo; o sinal vem do tipo.
            $table->decimal('valor', 12, 2);
            $table->date('data_movimento')->index();
            $table->string('descricao', 255);
            $table->foreignId('lancamento_id')->nullable()->constrained('lancamentos')->restrictOnDelete();
            $table->foreignId('lancamento_pagamento_id')->nullable()->constrained('lancamento_pagamentos')->nullOnDelete();
            $table->unsignedBigInteger('adiantamento_id')->nullable()->index();
            $table->foreignId('sessao_caixa_id')->nullable()->constrained('sessao_caixas')->nullOnDelete();
            $table->char('transferencia_uuid', 36)->nullable()->index();
            $table->foreignId('estorno_de_id')->nullable()->unique()->constrained('movimentacoes_financeiras')->restrictOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['conta_financeira_id', 'data_movimento'], 'mov_fin_conta_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentacoes_financeiras');
    }
};
