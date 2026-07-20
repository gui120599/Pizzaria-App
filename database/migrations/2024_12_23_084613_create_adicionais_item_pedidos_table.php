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
        Schema::create('adicionais_item_pedidos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aip_adicional_id');
            $table->unsignedBigInteger('aip_item_pedido_id');
            $table->double('aip_quantidade');
            $table->decimal('aip_valor_unitario', 10, 2)->default('0.00');
            $table->decimal('aip_valor_total', 10, 2)->default('0.00');
            $table->timestamps();

            $table->foreign('aip_adicional_id')->references('id')->on('adicionais');
            $table->foreign('aip_item_pedido_id')->references('id')->on('itens_pedidos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adicionais_item_pedidos');
    }
};
