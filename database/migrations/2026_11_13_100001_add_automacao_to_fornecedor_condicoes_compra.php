<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parâmetros da automação de compras por fornecedor (ver
     * docs/planos/compras-automatizadas.md). Tudo aditivo: sem preencher, o
     * fornecedor segue o fluxo manual de hoje.
     */
    public function up(): void
    {
        Schema::table('fornecedor_condicoes_compra', function (Blueprint $table) {
            // App\Enums\Compras\RegraBaixaCompra (null = manual).
            $table->string('fcc_regra_baixa', 30)->nullable();
            $table->foreignId('fcc_plano_despesa_id')->nullable()
                ->constrained('planos_despesas', 'id', 'fcc_plano_despesa_fk')->nullOnDelete();
            // App\Enums\Compras\AutonomiaAgente (null = vincular, o padrão).
            $table->string('fcc_autonomia_agente', 20)->nullable();
            $table->decimal('fcc_tolerancia_preco_pct', 5, 2)->default(15);
            // Entrada automática no estoque: emissão + N dias (0 = mesmo dia).
            $table->boolean('fcc_entrada_automatica')->default(false);
            $table->unsignedSmallInteger('fcc_entrada_dias_apos_emissao')->default(0);
            $table->text('fcc_observacoes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fornecedor_condicoes_compra', function (Blueprint $table) {
            $table->dropForeign('fcc_plano_despesa_fk');
            $table->dropColumn([
                'fcc_regra_baixa', 'fcc_plano_despesa_id', 'fcc_autonomia_agente', 'fcc_tolerancia_preco_pct',
                'fcc_entrada_automatica', 'fcc_entrada_dias_apos_emissao', 'fcc_observacoes',
            ]);
        });
    }
};
