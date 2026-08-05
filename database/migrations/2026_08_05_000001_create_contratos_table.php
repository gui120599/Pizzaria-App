<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('favorecido_id')
                ->constrained('prestadores')
                ->restrictOnDelete();
            $table->foreignId('plano_despesa_id')
                ->constrained('planos_despesas')
                ->restrictOnDelete();
            $table->string('descricao');
            $table->string('numero_documento')->nullable();
            $table->decimal('valor', 12, 2);
            // Dia do mês em que o lançamento gerado vence (1-31; clampado ao fim do mês
            // em meses mais curtos — ver Contrato::proximoVencimento/ContratoService).
            $table->unsignedTinyInteger('dia_vencimento');
            $table->string('forma_pagamento')->nullable();  // usa App\Enums\FormaPagamento
            $table->date('data_inicio');
            $table->date('data_fim')->nullable();
            // usa App\Enums\StatusContrato; Encerrado é setado automaticamente ao passar
            // data_fim (ver ContratoService::encerrarVencidos), além de manual/Suspenso.
            $table->string('status')->default('ativo')->index();
            $table->text('observacoes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos');
    }
};
