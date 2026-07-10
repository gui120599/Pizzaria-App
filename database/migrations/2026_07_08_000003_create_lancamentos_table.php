<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lancamentos', function (Blueprint $table) {
            $table->id();
            // Natureza do lançamento (usa App\Enums\TipoLancamento): pagar | receber
            $table->string('tipo')->index();
            // Classificação: só um dos dois planos é preenchido, conforme o tipo (restrictOnDelete já indexa a FK)
            $table->foreignId('plano_despesa_id')
                ->nullable()
                ->constrained('planos_despesas')
                ->restrictOnDelete();
            $table->foreignId('plano_receita_id')
                ->nullable()
                ->constrained('planos_receitas')
                ->restrictOnDelete();
            // Snapshot do comportamento copiado do plano de despesas na criação
            // (preserva a DRE histórica se a conta for reclassificada; null quando tipo = receber)
            $table->string('comportamento')->nullable();
            $table->string('descricao');
            $table->string('favorecido')->nullable();  // fornecedor/cliente (texto livre nesta fase)
            $table->string('numero_documento')->nullable();
            $table->decimal('valor', 12, 2);
            $table->date('vencimento')->index();
            $table->date('data_pagamento')->nullable();
            $table->string('status')->default('pendente')->index();  // usa App\Enums\StatusLancamento
            $table->string('forma_pagamento')->nullable();  // usa App\Enums\FormaPagamento
            $table->text('observacoes')->nullable();
            $table->timestamps();

            // "Vencido" é derivado (status = pendente e vencimento < hoje); não há status atrasado
            $table->index(['tipo', 'status', 'vencimento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lancamentos');
    }
};
