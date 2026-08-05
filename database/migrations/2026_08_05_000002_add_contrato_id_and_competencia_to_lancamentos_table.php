<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo do título a pagar com o contrato que o gerou automaticamente
     * (ver ContratoService::gerarLancamentoMensal). `competencia` (1º dia do
     * mês de referência) + `contrato_id` como unique composto garante a
     * idempotência: um contrato gera no máximo um lançamento por mês.
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('contrato_id')
                ->nullable()
                ->after('compra_id')
                ->constrained('contratos')
                ->nullOnDelete();
            $table->date('competencia')->nullable()->after('contrato_id');

            $table->unique(['contrato_id', 'competencia']);
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropUnique(['contrato_id', 'competencia']);
            $table->dropConstrainedForeignId('contrato_id');
            $table->dropColumn('competencia');
        });
    }
};
