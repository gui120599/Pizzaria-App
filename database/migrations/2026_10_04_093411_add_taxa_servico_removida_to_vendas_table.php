<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            // Taxa de serviço tirada no caixa: o VendaService deixa de cobrar
            // a taxa das mesas lançadas nesta venda.
            $table->boolean('venda_taxa_servico_removida')->default(false)->after('venda_valor_taxa_servico');
        });
    }

    public function down(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->dropColumn('venda_taxa_servico_removida');
        });
    }
};
