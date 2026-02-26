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
        Schema::create('movimentacao_pedidos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mov_pedido_pedido_id');
            $table->unsignedBigInteger('mov_pedido_sessao_mesa_id_anterior')->nullable();
            $table->unsignedBigInteger('mov_pedido_sessao_mesa_id_atual')->nullable();
            $table->unsignedBigInteger('mov_pedido_user_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('mov_pedido_pedido_id')->references('id')->on('pedidos');
            $table->foreign('mov_pedido_sessao_mesa_id_anterior')->references('id')->on('sessao_mesas');
            $table->foreign('mov_pedido_sessao_mesa_id_atual')->references('id')->on('sessao_mesas');
            $table->foreign('mov_pedido_user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimentacao_pedidos');
    }
};