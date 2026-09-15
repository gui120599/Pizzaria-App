<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            // Setado só na linha da oferta (ex.: brotinho): qual opção de
            // produto ofertado (dentre as N possíveis da regra) foi escolhida.
            // item_pedido_promocao_adicional_regra_id continua setado tanto no
            // gatilho (quando há override) quanto na linha da oferta — é ele
            // quem faz o "freeze" de preço em ItensPedido::recalcularValores().
            $table->foreignId('item_pedido_promocao_adicional_oferta_id')
                ->nullable()
                ->after('item_pedido_origem_id')
                ->constrained('promocao_adicional_ofertas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropForeign(['item_pedido_promocao_adicional_oferta_id']);
            $table->dropColumn('item_pedido_promocao_adicional_oferta_id');
        });
    }
};
