<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rota de produção: por quais setores um produto passa, em etapas
     * sequenciais. Exatamente um entre produto_id e categoria_id (validado no
     * model); a rota da categoria vale para as categorias descendentes.
     *
     * Sem soft delete, de propósito: a rota é editada por Repeater e uma linha
     * apagada "na lixeira" bloquearia o unique da mesma etapa. A auditoria dos
     * cadastros fica em cozinha_historicos.
     */
    public function up(): void
    {
        Schema::create('rotas_producao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produto_id')->nullable()->constrained('produtos')->cascadeOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->cascadeOnDelete();
            $table->foreignId('setor_producao_id')->constrained('setores_producao')->restrictOnDelete();
            $table->unsignedTinyInteger('etapa')->default(1);
            $table->unsignedSmallInteger('tempo_previsto_min')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['produto_id', 'etapa'], 'rota_prod_produto_etapa_uq');
            $table->unique(['categoria_id', 'etapa'], 'rota_prod_categoria_etapa_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotas_producao');
    }
};
