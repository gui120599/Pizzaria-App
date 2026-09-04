<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stone_pedidos', function (Blueprint $table) {
            $table->id();

            // Nomes de FK/índice explícitos e curtos — o padrão inferido pelo Laravel
            // (stone_pedidos_stp_..._foreign) estoura o limite de 64 chars do MySQL
            // e a FK falha silenciosamente (ver feedback_mysql_identifier_length).
            $table->foreignId('stp_venda_id')
                ->constrained('vendas', indexName: 'stp_venda_fk')
                ->cascadeOnDelete();
            $table->foreignId('stp_maquininha_id')
                ->nullable()
                ->constrained('maquininhas', indexName: 'stp_maquininha_fk')
                ->nullOnDelete();
            $table->foreignId('stp_opcaopagamento_id')
                ->nullable()
                ->constrained('opcoes_pagamentos', indexName: 'stp_opcaopag_fk')
                ->nullOnDelete();

            $table->string('stp_order_id')->nullable()->unique('stp_order_id_uk'); // or_xxx da Stone
            $table->string('stp_order_code')->nullable();
            $table->decimal('stp_valor_solicitado', 10, 2);
            $table->decimal('stp_valor_pago', 10, 2)->default(0);
            $table->string('stp_status', 14)->default('aguardando');
            $table->timestamp('stp_fechado_em')->nullable();
            $table->timestamps();

            $table->index(['stp_venda_id', 'stp_status'], 'stp_venda_status_idx');
            $table->index('stp_status', 'stp_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stone_pedidos');
    }
};
