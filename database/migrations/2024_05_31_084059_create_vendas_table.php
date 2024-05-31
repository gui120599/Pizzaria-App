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
        Schema::create('vendas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venda_sessao_caixa_id')->nullable();
            $table->unsignedBigInteger('venda_cliente_id')->nullable();

            $table->decimal('venda_valor_base_calculo_icms',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_icms',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_pis',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_cofins',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_frete',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_seguro',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_itens',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_desconto',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_total',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_pago',7,2)->nullable()->default('0.0');
            $table->decimal('venda_valor_troco',7,2)->nullable()->default('0.0');
            $table->enum('venda_status',['INICIADA','FINALIZADA',''])->default('INICIADA');
            $table->dateTime('venda_datahora_iniciada')->nullable();
            $table->dateTime('venda_datahora_finalizada')->nullable();
            $table->timestamps();

            //Chaves Estrangeiras
            $table->foreign('venda_sessao_caixa_id')->references('id')->on('sessao_caixas');
            $table->foreign('venda_cliente_id')->references('id')->on('clientes');

            //Criando Indexes estrangeiros
            $table->index('venda_sessao_caixa_id');
            $table->index('venda_cliente_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendas');
    }
};
