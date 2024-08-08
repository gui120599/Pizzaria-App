<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pagamentos_vendas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pg_venda_venda_id')->nullable();
            $table->unsignedBigInteger('pg_venda_opcaopagamento_id')->nullable();
            $table->unsignedBigInteger('pg_venda_cartao_id')->nullable();
            $table->integer('pg_venda_numero_autorizacao_cartao')->nullable();
            $table->enum('pg_venda_tipo_integracao',['integrated','notIntegrated'])->default('notIntegrated');
            $table->decimal('pg_venda_valor_pagamento',7,2)->default('0.0');
            $table->timestamps();

            $table->foreign('pg_venda_venda_id')->references('id')->on('vendas');
            $table->foreign('pg_venda_opcaopagamento_id')->references('id')->on('opcoes_pagamentos');
            $table->foreign('pg_venda_cartao_id')->references('id')->on('cartoes_pagamentos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagamentos_vendas');
    }
};
