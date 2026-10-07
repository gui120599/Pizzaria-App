<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perguntas', function (Blueprint $table) {
            $table->id();

            // Dona da pergunta: um produto OU uma categoria (vale para os
            // produtos dela, das filhas e para a pizza de sabores) — nunca os dois.
            $table->foreignId('pergunta_produto_id')->nullable()->constrained('produtos')->cascadeOnDelete();
            $table->foreignId('pergunta_categoria_id')->nullable()->constrained('categorias')->cascadeOnDelete();

            $table->string('pergunta_texto', 120);

            // Obrigatória quando o mínimo é 1 ou mais; máximo 1 = escolha única.
            $table->unsignedTinyInteger('pergunta_minimo')->default(0);
            $table->unsignedTinyInteger('pergunta_maximo')->default(1);

            $table->unsignedInteger('pergunta_ordem')->default(0);
            $table->boolean('pergunta_ativa')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perguntas');
    }
};
