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
        Schema::create('itens_vendas', function (Blueprint $table) {
            $table->id();
            $table->integer('item_numero');
            $table->unsignedBigInteger('item_venda_venda_id');
            $table->unsignedBigInteger('item_venda_produto_id');
            $table->double('item_venda_quantidade');
            $table->double('item_venda_quantidade_tributavel');
            $table->decimal('item_venda_valor_unitario',7,2)->default('0.00');
            $table->decimal('item_venda_valor_unitario_tributavel',7,2)->default('0.00');
            $table->decimal('item_venda_desconto',7,2)->default('0.00');
            $table->decimal('item_venda_valor',7,2)->default('0.00');
            $table->decimal('item_venda_base_calculo_icms',7,2)->default('0.00');
            $table->decimal('item_venda_valor_icms',7,2)->default('0.00');
            $table->decimal('item_venda_valor_total_tributos',7,2)->default('0.00');
            $table->text('item_venda_observacao')->nullable();
            $table->enum('item_venda_status',['INSERIDO','REMOVIDO'])->default('INSERIDO');
            $table->unsignedBigInteger('item_venda_usuario_removeu')->nullable();
            $table->timestamps();

            $table->foreign('item_venda_venda_id')->references('id')->on('vendas');
            $table->foreign('item_venda_produto_id')->references('id')->on('produtos');
            $table->foreign('item_venda_usuario_removeu')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itens_vendas');
    }
};
