<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma compra passa a poder gerar N títulos a pagar (parcelas), não mais só um.
     * Remove a unique de `compra_id` (mantém a FK) e adiciona rastreabilidade do
     * prazo de pagamento aplicado + número/total da parcela dentro do lote.
     *
     * Lançamentos já existentes (gerados antes desta mudança) ficam com
     * prazo_pagamento_id/parcela_numero/parcela_total nulos — tratados como
     * "compra com 1 título só", sem necessidade de backfill.
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            // A FK de compra_id se apoia no índice da unique; precisa de um índice
            // substituto antes de derrubar a unique, senão o MySQL recusa (erro 1553).
            $table->index('compra_id', 'lancamentos_compra_id_index');
            $table->dropUnique(['compra_id']);

            $table->foreignId('prazo_pagamento_id')
                ->nullable()
                ->after('contrato_id')
                ->constrained('prazos_pagamento')
                ->nullOnDelete();
            $table->unsignedSmallInteger('parcela_numero')->nullable()->after('prazo_pagamento_id');
            $table->unsignedSmallInteger('parcela_total')->nullable()->after('parcela_numero');
        });
    }

    /**
     * Reversível apenas se, no momento do rollback, nenhuma compra tiver mais de
     * um lançamento — do contrário a recriação da unique falha (esperado: esta
     * migration é forward-only depois que o parcelamento estiver em uso).
     */
    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prazo_pagamento_id');
            $table->dropColumn(['parcela_numero', 'parcela_total']);

            $table->unique('compra_id');
            $table->dropIndex('lancamentos_compra_id_index');
        });
    }
};
