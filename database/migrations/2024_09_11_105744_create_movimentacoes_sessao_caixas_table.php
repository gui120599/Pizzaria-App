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
        Schema::create('movimentacoes_sessao_caixas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mov_sessaocaixa_id')->nullable();
            $table->unsignedBigInteger('mov_venda_id')->nullable();
            $table->string('mov_descricao')->nullable();
            $table->enum('mov_tipo',['ENTRADA','SAIDA'])->default('ENTRADA');
            $table->decimal('mov_valor',10,2)->default('0.00');
            $table->text('mov_observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('mov_sessaocaixa_id')->references('id')->on('sessao_caixas');
            $table->foreign('mov_venda_id')->references('id')->on('vendas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimentacoes_sessao_caixas');
    }
};
