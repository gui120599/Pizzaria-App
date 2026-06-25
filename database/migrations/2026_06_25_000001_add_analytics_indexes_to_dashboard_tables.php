<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 1 (analytics): índices para os filtros usados nos dashboards.
 *
 * Sem eles, cada widget faz full table scan (EXPLAIN type=ALL) — lê a tabela
 * inteira (26k vendas / 46k pedidos) para devolver poucas centenas de linhas
 * do período. Com os índices a leitura vira range scan e fica ~constante
 * conforme as tabelas crescem. Não altera dado nem resultado, só velocidade.
 *
 * Ordem do composto depende do operador usado na query:
 * - vendas: status = 'FINALIZADA' (igualdade) → status antes da data.
 * - pedidos: status != 'INICIADO' (negação, não seletiva) → data antes do
 *   status, pois quem filtra de fato é o período.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            if (! $this->temIndice('vendas', 'vendas_status_finalizada_idx')) {
                $table->index(['venda_status', 'venda_datahora_finalizada'], 'vendas_status_finalizada_idx');
            }
        });

        Schema::table('pedidos', function (Blueprint $table) {
            if (! $this->temIndice('pedidos', 'pedidos_abertura_status_idx')) {
                $table->index(['pedido_datahora_abertura', 'pedido_status'], 'pedidos_abertura_status_idx');
            }
        });

        Schema::table('itens_vendas', function (Blueprint $table) {
            if (! $this->temIndice('itens_vendas', 'itens_vendas_status_idx')) {
                $table->index('item_venda_status', 'itens_vendas_status_idx');
            }
        });

        Schema::table('itens_pedidos', function (Blueprint $table) {
            if (! $this->temIndice('itens_pedidos', 'itens_pedidos_status_idx')) {
                $table->index('item_pedido_status', 'itens_pedidos_status_idx');
            }
        });

        Schema::table('produtos', function (Blueprint $table) {
            if (! $this->temIndice('produtos', 'produtos_tipo_idx')) {
                $table->index('produto_tipo', 'produtos_tipo_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendas', fn (Blueprint $table) => $table->dropIndex('vendas_status_finalizada_idx'));
        Schema::table('pedidos', fn (Blueprint $table) => $table->dropIndex('pedidos_abertura_status_idx'));
        Schema::table('itens_vendas', fn (Blueprint $table) => $table->dropIndex('itens_vendas_status_idx'));
        Schema::table('itens_pedidos', fn (Blueprint $table) => $table->dropIndex('itens_pedidos_status_idx'));
        Schema::table('produtos', fn (Blueprint $table) => $table->dropIndex('produtos_tipo_idx'));
    }

    private function temIndice(string $tabela, string $indice): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tabela)
            ->where('INDEX_NAME', $indice)
            ->exists();
    }
};
