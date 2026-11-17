<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido combinado como fiado / a prazo no atendimento (Atender Pedido e
 * Painel do Garçom): não exige forma de pagamento e, ao entrar no Nova Venda,
 * já marca a venda como fiado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->boolean('pedido_fiado')->default(false)->after('pedido_observacao_pagamento');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('pedido_fiado');
        });
    }
};
