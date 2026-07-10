<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocoes_relampago', function (Blueprint $table) {
            $table->id();
            $table->string('promocao_nome');
            $table->string('promocao_descricao')->nullable();
            $table->boolean('promocao_ativa')->default(true);
            $table->dateTime('promocao_inicio');
            $table->dateTime('promocao_fim');

            // Teto do pool: soma das unidades de todos os produtos da promoção.
            // Null = ilimitado (promoção só por janela de tempo).
            $table->unsignedInteger('promocao_qtd_total')->nullable();
            // Contador materializado. Decimal porque meia a meia consome frações
            // (0,5 + 0,5 = 1 pizza). Fonte de controle; os itens do pedido são a auditoria.
            $table->decimal('promocao_qtd_vendida', 10, 2)->default(0);

            $table->unsignedInteger('promocao_limite_por_pedido')->nullable();

            // Escassez: exibe "restam X" no cardápio apenas quando o saldo cai
            // até o limiar. Sem limiar, exibe desde o início.
            $table->boolean('promocao_exibe_contador')->default(true);
            $table->unsignedInteger('promocao_limiar_escassez')->nullable();

            // Quando falso, a promoção só vale para pizza de sabor único.
            $table->boolean('promocao_permite_sabores')->default(false);
            // Teto de sabores da promoção. Null = herda categoria_max_sabores do produto.
            $table->unsignedTinyInteger('promocao_max_sabores')->nullable();

            $table->unsignedInteger('promocao_ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Consulta quente do cardápio: promoções vigentes agora.
            $table->index(['promocao_ativa', 'promocao_inicio', 'promocao_fim'], 'idx_promocao_vigencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocoes_relampago');
    }
};
