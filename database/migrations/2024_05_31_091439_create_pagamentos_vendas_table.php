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
            $table->string('pg_venda_cartao_id')->nullable();
            $table->decimal('pg_venda_valor_pagamento',7,2)->default('0.0');
            $table->

            $table->timestamps();
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
