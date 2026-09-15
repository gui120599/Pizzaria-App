<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocao_adicional_regras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('par_promocao_id')
                ->constrained('promocoes_adicionais')
                ->cascadeOnDelete();
            $table->foreignId('par_produto_gatilho_id')
                ->constrained('produtos')
                ->restrictOnDelete();

            // Preço explícito do gatilho enquanto a campanha estiver vigente.
            // Em branco = o gatilho segue a cadeia normal de preço (relâmpago >
            // produto_preco_promocional > preço de venda), sem interferência
            // desta promoção. Quando setado, vence inclusive o relâmpago — ver
            // PrecificadorService::resolver().
            $table->decimal('par_preco_gatilho_override', 10, 2)->nullable();

            // Quantas vezes a oferta pode ser ACEITA no mesmo pedido, somando
            // todas as opções de produto ofertado (ver promocao_adicional_ofertas)
            // — não é a quantidade de unidades do gatilho. Padrão 1, null = sem limite.
            $table->unsignedInteger('par_qtd_maxima_por_pedido')->nullable()->default(1);

            $table->timestamps();

            // Uma config por gatilho por campanha — as N opções de produto
            // ofertado vivem em promocao_adicional_ofertas, não aqui, então
            // não há mais risco de duas regras do mesmo gatilho com overrides
            // conflitantes.
            $table->unique(['par_promocao_id', 'par_produto_gatilho_id'], 'unq_promoad_regra_gatilho');
            $table->index('par_produto_gatilho_id', 'idx_par_produto_gatilho');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocao_adicional_regras');
    }
};
