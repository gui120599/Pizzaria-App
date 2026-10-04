<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            // Imposto que incide sobre a taxa de serviço quando ela vai na
            // NFC-e (descontado da taxa dos garçons no relatório).
            $table->decimal('empresa_percentual_imposto_taxa_servico', 5, 2)->default(0)->after('empresa_regime_tributario');
        });

        Schema::table('vendas', function (Blueprint $table) {
            // Retrato do percentual acima quando a NFC-e da venda é autorizada.
            $table->decimal('venda_imposto_taxa_servico_percentual', 5, 2)->nullable()->after('venda_taxa_servico_removida');
        });
    }

    public function down(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->dropColumn('venda_imposto_taxa_servico_percentual');
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('empresa_percentual_imposto_taxa_servico');
        });
    }
};
