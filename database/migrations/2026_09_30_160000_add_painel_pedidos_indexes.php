<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para o Painel de Pedidos (Kanban no Filament).
 *
 * O índice que já existe, `pedidos_abertura_status_idx`, é
 * (pedido_datahora_abertura, pedido_status): a coluna líder é a data, feito
 * para os dashboards, que filtram por período e negam o status. As queries do
 * painel fazem o contrário — igualdade/IN em `pedido_status` e range na data —
 * então não conseguem seek por ele. Daí os compostos abaixo, com o status na
 * frente.
 *
 * O terceiro índice serve ao eager load dos itens do card, que sempre carrega
 * as linhas de um pedido filtrando por `item_pedido_status = 'INSERIDO'`. O
 * `itens_pedidos_status_idx` existente (só o status) é pouco seletivo, porque
 * INSERIDO domina a tabela.
 *
 * Nomes curtos de propósito: identificador de índice no MySQL estoura em 64
 * caracteres e a falha é silenciosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            if (! $this->temIndice('pedidos', 'pedidos_status_abertura_idx')) {
                $table->index(['pedido_status', 'pedido_datahora_abertura'], 'pedidos_status_abertura_idx');
            }

            if (! $this->temIndice('pedidos', 'pedidos_status_entrega_idx')) {
                $table->index(['pedido_status', 'pedido_datahora_entrega'], 'pedidos_status_entrega_idx');
            }
        });

        Schema::table('itens_pedidos', function (Blueprint $table) {
            if (! $this->temIndice('itens_pedidos', 'itens_pedidos_pedido_status_idx')) {
                $table->index(['item_pedido_pedido_id', 'item_pedido_status'], 'itens_pedidos_pedido_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            if ($this->temIndice('pedidos', 'pedidos_status_abertura_idx')) {
                $table->dropIndex('pedidos_status_abertura_idx');
            }

            if ($this->temIndice('pedidos', 'pedidos_status_entrega_idx')) {
                $table->dropIndex('pedidos_status_entrega_idx');
            }
        });

        Schema::table('itens_pedidos', function (Blueprint $table) {
            if ($this->temIndice('itens_pedidos', 'itens_pedidos_pedido_status_idx')) {
                $table->dropIndex('itens_pedidos_pedido_status_idx');
            }
        });
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
