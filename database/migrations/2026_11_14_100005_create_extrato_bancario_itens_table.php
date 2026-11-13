<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extrato bancário importado por API (hoje Banco do Brasil), para
     * conciliar boletos pagos. Linha nunca muda de valor: só status e vínculo.
     */
    public function up(): void
    {
        Schema::table('contas_financeiras', function (Blueprint $table) {
            // banco_brasil (null = sem integração).
            $table->string('integracao', 20)->nullable();
            $table->date('ultimo_extrato_em')->nullable();
        });

        Schema::create('extrato_bancario_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_financeira_id')->constrained('contas_financeiras', 'id', 'ebi_conta_fk')->restrictOnDelete();
            $table->date('data_lancamento');
            $table->date('data_movimento')->nullable();
            $table->decimal('valor', 12, 2);
            // D (débito) | C (crédito)
            $table->char('natureza', 1);
            $table->string('historico', 120);
            $table->string('documento', 40)->nullable();
            $table->string('info_complementar', 255)->nullable();
            $table->char('hash_unico', 64)->unique('ebi_hash_unico');
            // App\Enums\Financeiro\StatusConciliacaoExtrato
            $table->string('status_conciliacao', 20)->default('pendente');
            $table->foreignId('movimentacao_financeira_id')->nullable()
                ->constrained('movimentacoes_financeiras', 'id', 'ebi_mov_fk')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('status_conciliacao', 'ebi_status_idx');
            $table->index(['conta_financeira_id', 'data_lancamento'], 'ebi_conta_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extrato_bancario_itens');

        Schema::table('contas_financeiras', function (Blueprint $table) {
            $table->dropColumn(['integracao', 'ultimo_extrato_em']);
        });
    }
};
