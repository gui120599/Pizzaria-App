<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O percentual de imposto deixa de ser só da taxa de serviço e vira o
     * imposto da NFC-e: vale para as vendas no Relatório de Fechamento de
     * Caixa e para a taxa de serviço no relatório dos garçons. Os valores
     * já gravados são mantidos.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->renameColumn('empresa_percentual_imposto_taxa_servico', 'empresa_percentual_imposto_nfe');
        });

        Schema::table('vendas', function (Blueprint $table) {
            $table->renameColumn('venda_imposto_taxa_servico_percentual', 'venda_imposto_nfe_percentual');
        });

        // Com a empresa ainda sem percentual (0), as notas autorizadas até
        // aqui gravaram retrato 0 — não era alíquota, era campo não
        // preenchido. Volta para nulo, e o relatório usa o percentual que a
        // empresa cadastrar.
        if ((float) DB::table('empresas')->value('empresa_percentual_imposto_nfe') == 0.0) {
            DB::table('vendas')->where('venda_imposto_nfe_percentual', 0)->update(['venda_imposto_nfe_percentual' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->renameColumn('empresa_percentual_imposto_nfe', 'empresa_percentual_imposto_taxa_servico');
        });

        Schema::table('vendas', function (Blueprint $table) {
            $table->renameColumn('venda_imposto_nfe_percentual', 'venda_imposto_taxa_servico_percentual');
        });
    }
};
