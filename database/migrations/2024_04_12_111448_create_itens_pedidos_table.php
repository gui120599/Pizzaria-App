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
        Schema::create('itens_pedidos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_pedido_produto_id');
            $table->unsignedBigInteger('item_pedido_pedido_id');
            $table->double('item_pedido_quantidade');
            $table->decimal('item_pedido_desconto',7,2)->default('0.00');
            $table->decimal('item_pedido_valor',7,2)->default('0.00');
            $table->text('item_pedido_observacao')->nullable();
            $table->enum('item_pedido_status',['INSERIDO','REMOVIDO'])->default('INSERIDO');
            $table->unsignedBigInteger('item_pedido_usuario_removeu')->nullable();
            $table->timestamps();

            $table->foreign('item_pedido_produto_id')->references('id')->on('produtos');
            $table->foreign('item_pedido_pedido_id')->references('id')->on('pedidos');
            $table->foreign('item_pedido_usuario_removeu')->references('id')->on('users');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itens_pedidos');
    }
};
