<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Onde a empresa guarda dinheiro (caixa PDV, cofre, banco, conta digital).
     * Desativar = soft delete (padrão do financeiro, sem coluna `ativo`).
     */
    public function up(): void
    {
        Schema::create('contas_financeiras', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80)->unique();
            // App\Enums\Financeiro\TipoContaFinanceira
            $table->string('tipo', 30)->index();
            $table->decimal('saldo_inicial', 12, 2)->default(0);
            $table->date('data_saldo_inicial');
            // Cache: saldo_inicial + movimentações (ver MovimentacaoService::recalcularSaldo).
            $table->decimal('saldo_atual', 12, 2)->default(0);
            $table->boolean('permite_saldo_negativo')->default(false);
            $table->string('banco', 80)->nullable();
            $table->string('agencia', 20)->nullable();
            $table->string('conta', 30)->nullable();
            // App\Enums\TipoChavePix
            $table->string('tipo_chave_pix', 20)->nullable();
            $table->text('chave_pix')->nullable();  // cast encrypted
            $table->string('cor', 20)->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_financeiras');
    }
};
