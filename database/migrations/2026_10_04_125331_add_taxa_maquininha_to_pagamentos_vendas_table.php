<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagamentos_vendas', function (Blueprint $table) {
            $table->foreignId('pg_venda_maquininha_id')->nullable()->after('pg_venda_cartao_id')
                ->constrained('maquininhas')->nullOnDelete();
            // Retrato da taxa da maquininha no momento do pagamento: mudar a
            // taxa cadastrada não altera relatórios antigos. Nulo = sem taxa
            // cadastrada na época (o relatório usa a taxa atual).
            $table->decimal('pg_venda_taxa_maquininha_percentual', 5, 2)->nullable()->after('pg_venda_valor_desconto');
            $table->decimal('pg_venda_taxa_maquininha_valor', 10, 2)->nullable()->after('pg_venda_taxa_maquininha_percentual');
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos_vendas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pg_venda_maquininha_id');
            $table->dropColumn(['pg_venda_taxa_maquininha_percentual', 'pg_venda_taxa_maquininha_valor']);
        });
    }
};
