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
        Schema::create('movimentacao_produtos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mov_produto_id');
            $table->decimal('mov_quantidade', 7, 2);
            $table->string('mov_tipo');
            $table->text('mov_motivo')->nullable();
            $table->integer('mov_venda_id')->nullable();
            $table->unsignedBigInteger('mov_user_id'); // Adiciona a coluna user_id
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('mov_produto_id')->references('id')->on('produtos');
            $table->foreign('mov_user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimentacao_produtos');
    }
};
