<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linha de produção da cozinha (ex.: "Pizzas", "Bar") — associada a um
 * conjunto de categorias, usada pra filtrar o Painel de Pedidos por estação
 * (cada estação só precisa ver pedidos com item de sua linha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linhas_producao', function (Blueprint $table) {
            $table->id();
            $table->string('linha_nome')->unique();
            $table->timestamps();
        });

        Schema::create('categoria_linha_producao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->cascadeOnDelete();
            $table->foreignId('linha_producao_id')->constrained('linhas_producao')->cascadeOnDelete();

            $table->unique(['categoria_id', 'linha_producao_id'], 'categoria_linha_producao_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categoria_linha_producao');
        Schema::dropIfExists('linhas_producao');
    }
};
