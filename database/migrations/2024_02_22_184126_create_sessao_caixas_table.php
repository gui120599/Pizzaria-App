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
        Schema::create('sessao_caixas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sessaocaixa_caixa_id');
            $table->enum('sessaocaixa_status',['ABERTA','FECHADA'])->default('FECHADA');
            $table->dateTime('sessaocaixa_data_hora_abertura');
            $table->dateTime('sessaocaixa_data_hora_fechamento')->nullable();
            $table->decimal('sessaocaixa_saldo_inicial',10,2)->default('0.00');
            $table->decimal('sessacaixa_saldo_final',10,2)->default('0.00');
            $table->unsignedBigInteger('sessaocaixa_user_id');
            $table->text('sessaocaixa_observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            //Chaves estrangeiras
            $table->foreign('sessaocaixa_caixa_id')->references('id')->on('caixas');
            $table->foreign('sessaocaixa_user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessao_caixas');
    }
};
