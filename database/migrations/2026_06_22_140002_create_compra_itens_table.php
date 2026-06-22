<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ci_compra_id')->constrained('compras')->cascadeOnDelete();
            // Insumo do estoque (nullable enquanto não mapeado — ex.: item de XML pendente).
            $table->foreignId('ci_produto_id')->nullable()->constrained('produtos')->nullOnDelete();
            $table->string('ci_descricao_fornecedor')->nullable(); // xProd da NFe
            $table->string('ci_codigo_fornecedor')->nullable();    // cProd da NFe
            $table->decimal('ci_quantidade_compra', 12, 4)->default(0);
            $table->string('ci_unidade_compra')->nullable();
            $table->decimal('ci_fator_conversao', 12, 4)->default(1); // un. de compra -> un. de estoque
            $table->decimal('ci_custo_unitario_compra', 12, 4)->default(0);
            $table->decimal('ci_valor_rateio', 12, 4)->default(0); // rateio de frete/desconto/outros
            $table->string('ci_lote_codigo')->nullable();
            $table->date('ci_validade')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('ci_compra_id');
            $table->index('ci_produto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_itens');
    }
};
