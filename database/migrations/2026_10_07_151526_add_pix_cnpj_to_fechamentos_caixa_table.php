<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PIX CNPJ ganha conferência própria no fechamento: esperado (vendas e
     * recebimentos nas formas PIX CNPJ) × valor do extrato do banco.
     */
    public function up(): void
    {
        Schema::table('fechamentos_caixa', function (Blueprint $table) {
            $table->decimal('total_esperado_pix_cnpj', 12, 2)->default(0)->after('total_esperado_pix');
            $table->decimal('valor_pix_cnpj', 12, 2)->default(0)->after('total_esperado_outros');
        });
    }

    public function down(): void
    {
        Schema::table('fechamentos_caixa', function (Blueprint $table) {
            $table->dropColumn(['total_esperado_pix_cnpj', 'valor_pix_cnpj']);
        });
    }
};
