<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parte do desconto da promoção combo rateada nesta linha. Como no cupom,
     * item_pedido_valor já é líquido dela (ver PromocaoComboService).
     */
    public function up(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->decimal('item_pedido_desconto_combo', 10, 2)->default(0)->after('item_pedido_desconto_cupom');
        });
    }

    public function down(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropColumn('item_pedido_desconto_combo');
        });
    }
};
