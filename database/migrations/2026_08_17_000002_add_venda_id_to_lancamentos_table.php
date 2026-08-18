<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo do título a receber com a venda do PDV que o originou (venda
     * fiado / com saldo em aberto). Rastreabilidade only — uma venda pode, em
     * tese, gerar mais de um título ao longo do tempo (não há unique aqui).
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('venda_id')
                ->nullable()
                ->after('cliente_id')
                ->constrained('vendas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venda_id');
        });
    }
};
