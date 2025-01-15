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
        Schema::create('adicionais_item_vendas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aiv_adicional_id');
            $table->unsignedBigInteger('aiv_item_venda_id');
            $table->double('aiv_quantidade');
            $table->decimal('aiv_valor_unitario',10,2)->default('0.00');
            $table->decimal('aiv_valor_total',10,2)->default('0.00');
            $table->timestamps();

            $table->foreign('aiv_adicional_id')->references('id')->on('adicionais');
            $table->foreign('aiv_item_venda_id')->references('id')->on('itens_vendas');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adicionais_item_vendas');
    }
};
