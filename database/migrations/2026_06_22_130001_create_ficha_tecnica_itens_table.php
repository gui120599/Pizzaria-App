<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ficha_tecnica_itens', function (Blueprint $table) {
            $table->id();
            // Produto produzido (pai) que consome este item na sua receita.
            $table->foreignId('fti_produto_id')->constrained('produtos')->cascadeOnDelete();
            // Insumo/componente (pode ser outro produzido — semi-acabado/codimentação).
            $table->foreignId('fti_insumo_id')->constrained('produtos')->cascadeOnDelete();
            $table->decimal('fti_quantidade', 12, 4)->default(0);
            $table->string('fti_unidade')->nullable();
            $table->decimal('fti_percentual_perda', 5, 2)->default(0); // fator de perda no preparo
            $table->timestamps();
            $table->softDeletes();

            $table->index('fti_produto_id');
            $table->index('fti_insumo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ficha_tecnica_itens');
    }
};
