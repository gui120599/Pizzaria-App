<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem participa da promoção combo: categorias (com subcategorias) ou
     * produtos, e exclusões ("todas as grandes, menos a de camarão").
     * Cada linha tem exatamente uma das duas FKs (validado no formulário).
     */
    public function up(): void
    {
        Schema::create('promocao_combo_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pcp_promocao_combo_id')->constrained('promocoes_combo')->cascadeOnDelete();
            $table->foreignId('pcp_categoria_id')->nullable()->constrained('categorias')->cascadeOnDelete();
            $table->foreignId('pcp_produto_id')->nullable()->constrained('produtos')->cascadeOnDelete();
            $table->boolean('pcp_excluir')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocao_combo_participantes');
    }
};
