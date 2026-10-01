<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A coluna final do Painel de Pedidos passa a mostrar ENTREGUE + FINALIZADO
 * (pedido pago no caixa, que costuma não ter pedido_datahora_entrega — só
 * pedido_datahora_finalizado). O índice (pedido_status, pedido_datahora_entrega)
 * de 2026_09_30_160000 não cobre um filtro por pedido_datahora_finalizado.
 * FINALIZADO é o maior status da tabela pedidos, então essa query merece
 * índice próprio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            if (! $this->temIndice('pedidos', 'pedidos_status_finalizado_idx')) {
                $table->index(['pedido_status', 'pedido_datahora_finalizado'], 'pedidos_status_finalizado_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            if ($this->temIndice('pedidos', 'pedidos_status_finalizado_idx')) {
                $table->dropIndex('pedidos_status_finalizado_idx');
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
