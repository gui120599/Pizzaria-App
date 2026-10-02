<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            // Fica separada de venda_valor_acrescimo (taxa da forma de
            // pagamento) para relatório/rateio e para o tratamento fiscal.
            $table->decimal('venda_valor_taxa_servico', 10, 2)->default(0)->after('venda_valor_acrescimo');
        });
    }

    public function down(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->dropColumn('venda_valor_taxa_servico');
        });
    }
};
