<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Leve também": produtos sugeridos depois de lançar o produto (ex.: a
     * pizza sugere refrigerantes), pelo preço normal, em todos os pontos de
     * pedido. plt_ordem em branco vai para o fim, na ordem do cardápio.
     */
    public function up(): void
    {
        Schema::create('produto_leve_tambem', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plt_produto_id');
            $table->unsignedBigInteger('plt_sugerido_id');
            $table->unsignedInteger('plt_ordem')->nullable();
            $table->timestamps();

            $table->unique(['plt_produto_id', 'plt_sugerido_id'], 'plt_par_unique');
            $table->foreign('plt_produto_id', 'plt_produto_fk')->references('id')->on('produtos')->cascadeOnDelete();
            $table->foreign('plt_sugerido_id', 'plt_sugerido_fk')->references('id')->on('produtos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_leve_tambem');
    }
};
