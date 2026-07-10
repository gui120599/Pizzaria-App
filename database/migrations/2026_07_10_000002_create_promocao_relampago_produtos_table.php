<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocao_relampago_produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prp_promocao_id')
                ->constrained('promocoes_relampago')
                ->cascadeOnDelete();
            $table->foreignId('prp_produto_id')
                ->constrained('produtos')
                ->restrictOnDelete();

            // Preço vive no pivô: a mesma promoção pode ter calabresa a 39,90 e
            // marguerita a 44,90 sem virar duas promoções.
            $table->decimal('prp_preco_promocional', 10, 2);

            // Sublimite opcional por produto, dentro do pool da promoção.
            $table->unsignedInteger('prp_qtd_total')->nullable();
            $table->decimal('prp_qtd_vendida', 10, 2)->default(0);

            $table->timestamps();

            $table->unique(['prp_promocao_id', 'prp_produto_id'], 'unq_promocao_produto');
            $table->index('prp_produto_id', 'idx_prp_produto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocao_relampago_produtos');
    }
};
