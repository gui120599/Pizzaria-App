<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compra: marca as notas que passam pela automação (DF-e e comando
     * compras:importar-xml). Item: EAN, vínculo com o de-para, acréscimos do
     * próprio <det> e o custo de aquisição calculado (CustoAquisicaoCalculator).
     */
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->boolean('compra_importacao_automatica')->default(false);
        });

        Schema::table('compra_itens', function (Blueprint $table) {
            $table->string('ci_ean', 14)->nullable();
            $table->foreignId('ci_vinculo_id')->nullable()
                ->constrained('fornecedor_produtos', 'id', 'ci_vinculo_fk')->nullOnDelete();
            $table->decimal('ci_valor_frete', 12, 4)->default(0);
            $table->decimal('ci_valor_seguro', 12, 4)->default(0);
            $table->decimal('ci_valor_outros', 12, 4)->default(0);
            $table->decimal('ci_custo_aquisicao_total', 12, 2)->nullable();
            $table->decimal('ci_custo_unitario_aquisicao', 12, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('compra_itens', function (Blueprint $table) {
            $table->dropForeign('ci_vinculo_fk');
            $table->dropColumn([
                'ci_ean', 'ci_vinculo_id', 'ci_valor_frete', 'ci_valor_seguro', 'ci_valor_outros',
                'ci_custo_aquisicao_total', 'ci_custo_unitario_aquisicao',
            ]);
        });

        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn('compra_importacao_automatica');
        });
    }
};
