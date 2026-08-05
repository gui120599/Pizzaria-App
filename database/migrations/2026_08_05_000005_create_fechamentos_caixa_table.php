<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fechamentos_caixa', function (Blueprint $table) {
            $table->id();
            // Uma sessão de caixa tem no máximo um fechamento (unique).
            $table->foreignId('sessao_caixa_id')
                ->unique()
                ->constrained('sessao_caixas')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            // usa App\Enums\StatusFechamentoCaixa; trava edição quando confirmado.
            $table->string('status')->default('rascunho')->index();
            // Snapshot do total esperado por forma de pagamento (calculado a partir de
            // pagamentos_vendas via opcoes_pagamentos.opcaopag_desc_nfe — ver
            // FechamentoCaixaService::calcularEsperado). Recalculado a cada save
            // enquanto em rascunho; travado ao confirmar.
            $table->decimal('total_esperado_dinheiro', 12, 2)->default(0);
            $table->decimal('total_esperado_debito', 12, 2)->default(0);
            $table->decimal('total_esperado_credito', 12, 2)->default(0);
            $table->decimal('total_esperado_pix', 12, 2)->default(0);
            $table->decimal('total_esperado_outros', 12, 2)->default(0);
            $table->dateTime('confirmado_em')->nullable();
            $table->text('observacoes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamentos_caixa');
    }
};
