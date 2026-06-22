<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fornecedor_produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fp_prestador_id')->constrained('prestadores')->cascadeOnDelete();
            $table->foreignId('fp_produto_id')->constrained('produtos')->cascadeOnDelete();
            $table->string('fp_codigo_fornecedor')->nullable(); // cProd da NFe
            $table->string('fp_descricao_fornecedor')->nullable();
            $table->string('fp_unidade_compra')->nullable();
            $table->decimal('fp_fator_conversao', 12, 4)->default(1); // 1 un. de compra = X un. de estoque
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['fp_prestador_id', 'fp_codigo_fornecedor'], 'fornecedor_produto_codigo_unique');
            $table->index('fp_produto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fornecedor_produtos');
    }
};
