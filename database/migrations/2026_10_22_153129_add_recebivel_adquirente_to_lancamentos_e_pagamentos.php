<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Título a receber de adquirente: `maquininha_id` marca o título como
     * recebível e `taxa_adquirente` guarda o MDR previsto (retrato), lançado
     * como despesa na liquidação. `conciliado_em` marca a baixa conferida com
     * o extrato da adquirente.
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('maquininha_id')
                ->nullable()
                ->constrained('maquininhas', indexName: 'lanc_maquininha_fk')
                ->nullOnDelete();
            $table->decimal('taxa_adquirente', 10, 2)->nullable();
        });

        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->dateTime('conciliado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->dropColumn('conciliado_em');
        });

        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropForeign('lanc_maquininha_fk');
            $table->dropColumn(['maquininha_id', 'taxa_adquirente']);
        });
    }
};
