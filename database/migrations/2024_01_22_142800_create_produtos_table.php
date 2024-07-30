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
        Schema::create('produtos', function (Blueprint $table) {
            $table->id();
            $table->string('produto_descricao');
            $table->string('produto_codimentacao')->nullable();
            $table->string('produto_codigo_NCM')->nullable();
            $table->string('produto_codigo_CEST')->nullable()->default('0000000');
            $table->string('produto_codigo_EAN')->nullable()->default('SEM GTIN');
            $table->string('produto_codigo_beneficio_fiscal_uf')->nullable();
            $table->string('produto_CFOP')->nullable();
            $table->unsignedBigInteger('produto_categoria_id');
            $table->string('produto_foto')->nullable();
            $table->string('produto_unidade_comercial')->nullable();
            $table->decimal('produto_preco_custo',7,2)->nullable();
            $table->decimal('produto_valor_percentual_venda',7,2)->nullable();
            $table->decimal('produto_preco_venda',7,2)->nullable();
            $table->decimal('produto_valor_percentual_comissao')->nullable();
            $table->decimal('produto_preco_comissao',7,2)->nullable();
            $table->decimal('produto_preco_promocional',7,2)->nullable();
            $table->string('produto_cod_origem_mercadoria')->nullable();
            $table->string('produto_cod_tributacao_icms')->nullable();
            $table->decimal('produto_valor_percentual_icms')->nullable();
            $table->decimal('produto_valor_percentual_cofins')->nullable();
            $table->decimal('produto_valor_percentual_pis')->nullable();
            $table->decimal('produto_valor_percentual_reducao_icms')->nullable();
            $table->date('produto_data_inicio_promocao')->nullable();
            $table->date('produto_data_final_promocao')->nullable();
            $table->integer('produto_quantidade_minima')->nullable();
            $table->integer('produto_quantidade_maxima')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('produto_categoria_id')->references('id')->on('categorias');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produtos');
    }
};
