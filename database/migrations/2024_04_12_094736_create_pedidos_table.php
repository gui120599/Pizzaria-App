<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pedido_cliente_id')->nullable();
            $table->unsignedBigInteger('pedido_sessao_mesa_id')->nullable();
            $table->unsignedBigInteger('pedido_usuario_garcom_id')->nullable();
            $table->unsignedBigInteger('pedido_usuario_entrega_id')->nullable();
            $table->unsignedBigInteger('pedido_opcaoentrega_id')->nullable();
            $table->string('pedido_descricao_pagamento')->nullable();
            $table->text('pedido_observacao_pagamento')->nullable();
            $table->text('pedido_endereco_entrega')->nullable();
            $table->decimal('pedido_valor_itens', 10,2)->nullable()->default('0.00');
            $table->decimal('pedido_valor_desconto', 10,2)->nullable()->default('0.00');
            $table->decimal('pedido_valor_total', 10,2)->nullable()->default('0.00');
            $table->enum('pedido_status', ['INICIADO', 'ABERTO', 'PREPARANDO', 'PRONTO', 'EM TRANSPORTE', 'ENTREGUE', 'FINALIZADO', 'CANCELADO'])->default('INICIADO');
            $table->dateTime('pedido_datahora_incio')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('pedido_datahora_abertura')->nullable();
            $table->dateTime('pedido_datahora_preparo')->nullable();
            $table->dateTime('pedido_datahora_pronto')->nullable();
            $table->dateTime('pedido_datahora_transporte')->nullable();
            $table->dateTime('pedido_datahora_entrega')->nullable();
            $table->dateTime('pedido_datahora_finalizado')->nullable();
            $table->dateTime('pedido_datahora_cancelado')->nullable();
            $table->timestamps();

            $table->foreign('pedido_cliente_id')->references('id')->on('clientes');
            $table->foreign('pedido_sessao_mesa_id')->references('id')->on('sessao_mesas');
            $table->foreign('pedido_usuario_garcom_id')->references('id')->on('users');
            $table->foreign('pedido_usuario_entrega_id')->references('id')->on('users');
            $table->foreign('pedido_opcaoentrega_id')->references('id')->on('opcoes_entregas');

            // Adicionando índices estrangeiros
            $table->index('pedido_cliente_id');
            $table->index('pedido_sessao_mesa_id');
            $table->index('pedido_usuario_garcom_id');
            $table->index('pedido_usuario_entrega_id');
            $table->index('pedido_opcaoentrega_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
