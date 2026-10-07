<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recebimento de título a receber (fiado) em cartão/Pix fica vinculado à
     * maquininha e guarda o retrato da taxa — mesmo modelo de pagamentos_vendas
     * (ver TaxaMaquininhaService). Percentual nulo = sem taxa cadastrada.
     */
    public function up(): void
    {
        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->foreignId('maquininha_id')
                ->nullable()
                ->after('numero_autorizacao_cartao')
                ->constrained('maquininhas')
                ->nullOnDelete();
            $table->decimal('taxa_percentual', 5, 2)->nullable()->after('maquininha_id');
            $table->decimal('taxa_valor', 12, 2)->nullable()->after('taxa_percentual');
        });
    }

    public function down(): void
    {
        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maquininha_id');
            $table->dropColumn(['taxa_percentual', 'taxa_valor']);
        });
    }
};
