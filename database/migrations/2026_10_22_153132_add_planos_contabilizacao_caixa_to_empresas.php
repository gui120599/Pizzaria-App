<?php

use App\Enums\Comportamento;
use App\Enums\Periodicidade;
use App\Models\Empresa;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planos usados na contabilização do fechamento de caixa: taxa de cartão
     * (MDR), quebra e sobra de caixa. Cria "Quebra de caixa" e "Sobra de
     * caixa" se não existirem e preenche a Empresa com eles e com "Taxas de
     * cartão" quando já houver.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            foreach (['empresa_plano_despesa_taxa_cartao_id' => 'emp_plano_taxa_fk', 'empresa_plano_despesa_quebra_caixa_id' => 'emp_plano_quebra_fk'] as $coluna => $indice) {
                $table->foreignId($coluna)->nullable()->constrained('planos_despesas', indexName: $indice)->nullOnDelete();
            }

            $table->foreignId('empresa_plano_receita_sobra_caixa_id')->nullable()->constrained('planos_receitas', indexName: 'emp_plano_sobra_fk')->nullOnDelete();
        });

        $grupoDespesa = PlanoDespesa::firstOrCreate(
            ['nome' => 'Administrativas', 'pai_id' => null],
            ['comportamento' => Comportamento::Fixo, 'periodicidade' => Periodicidade::Mensal],
        );
        $quebra = PlanoDespesa::firstOrCreate(
            ['nome' => 'Quebra de caixa', 'pai_id' => $grupoDespesa->id],
            ['comportamento' => Comportamento::Variavel, 'periodicidade' => Periodicidade::Eventual],
        );
        $taxaCartao = PlanoDespesa::where('nome', 'Taxas de cartão')->whereNotNull('pai_id')->first();

        $grupoReceita = PlanoReceita::firstOrCreate(['nome' => 'Outras', 'pai_id' => null]);
        $sobra = PlanoReceita::firstOrCreate(['nome' => 'Sobra de caixa', 'pai_id' => $grupoReceita->id]);

        Empresa::query()->update([
            'empresa_plano_despesa_taxa_cartao_id' => $taxaCartao?->id,
            'empresa_plano_despesa_quebra_caixa_id' => $quebra->id,
            'empresa_plano_receita_sobra_caixa_id' => $sobra->id,
        ]);
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropForeign('emp_plano_taxa_fk');
            $table->dropForeign('emp_plano_quebra_fk');
            $table->dropForeign('emp_plano_sobra_fk');
            $table->dropColumn(['empresa_plano_despesa_taxa_cartao_id', 'empresa_plano_despesa_quebra_caixa_id', 'empresa_plano_receita_sobra_caixa_id']);
        });
    }
};
