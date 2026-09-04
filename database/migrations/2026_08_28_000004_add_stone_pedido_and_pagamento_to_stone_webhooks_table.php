<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_webhooks', function (Blueprint $table) {
            // Liga o evento ao pedido do PDV e ao pagamento que ele gerou.
            // O charge.refunded acha o pagamento pelo stw_charge_id do charge.paid original.
            $table->foreignId('stw_stone_pedido_id')
                ->nullable()
                ->after('stw_venda_id')
                ->constrained('stone_pedidos', indexName: 'stw_stone_pedido_fk')
                ->nullOnDelete();
            $table->foreignId('stw_pagamento_venda_id')
                ->nullable()
                ->after('stw_stone_pedido_id')
                ->constrained('pagamentos_vendas', indexName: 'stw_pag_venda_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stone_webhooks', function (Blueprint $table) {
            $table->dropForeign('stw_stone_pedido_fk');
            $table->dropForeign('stw_pag_venda_fk');
            $table->dropColumn(['stw_stone_pedido_id', 'stw_pagamento_venda_id']);
        });
    }
};
