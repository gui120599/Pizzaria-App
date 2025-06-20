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
        Schema::create('itens_combo_produtos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_combo_produto_combo_id');
            $table->unsignedBigInteger('item_combo_produto_produto_id');
            $table->double('item_combo_produto_quantidade_produto')->nullable();
            $table->decimal('item_combo_produto_valor_produto')->default('0.00')->nullable();
            $table->decimal('item_combo_produto_valor_desconto')->default('0.00')->nullable();
            $table->decimal('item_combo_produto_valor_total')->default('0.00')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('item_combo_produto_combo_id')->references('id')->on('combo_produtos');
            $table->foreign('item_combo_produto_produto_id')->references('id')->on('produtos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itens_combo_produtos');
    }
};
