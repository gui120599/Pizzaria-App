<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\Compras\OrigemVinculo;
use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\CompraStatusEnum;
use App\Enums\Periodicidade;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Fornecedores\Pages\EditFornecedor;
use App\Filament\Resources\FornecedorProdutos\Pages\ManageFornecedorProdutos;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\FornecedorCondicaoCompra;
use App\Models\FornecedorProduto;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\CompraService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ComprasAutomatizadasTelasTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_configura_a_automacao_do_fornecedor(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $fornecedor = $this->fornecedor();

        Livewire::test(EditFornecedor::class, ['record' => $fornecedor->getKey()])
            ->fillForm(['condicaoCompra' => [
                'fcc_regra_baixa' => RegraBaixaCompra::AutomaticaNaEmissao->value,
                'fcc_entrada_automatica' => true,
                'fcc_entrada_dias_apos_emissao' => 2,
                'fcc_tolerancia_preco_pct' => 20,
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $condicao = $fornecedor->fresh()->condicaoCompra;
        $this->assertSame(RegraBaixaCompra::AutomaticaNaEmissao, $condicao->fcc_regra_baixa);
        $this->assertTrue($condicao->fcc_entrada_automatica);
        $this->assertSame(2, $condicao->fcc_entrada_dias_apos_emissao);
        $this->assertEquals(20, $condicao->fcc_tolerancia_preco_pct);
    }

    public function test_gerente_ve_a_automacao_mas_nao_altera(): void
    {
        Permission::firstOrCreate(['name' => 'view_any:prestador', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'update:prestador', 'guard_name' => 'web']);
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $gerente->givePermissionTo(['view_any:prestador', 'update:prestador']);
        $this->actingAs($gerente);

        $fornecedor = $this->fornecedor();
        FornecedorCondicaoCompra::query()->where('fcc_prestador_id', $fornecedor->id)
            ->update(['fcc_regra_baixa' => RegraBaixaCompra::AutomaticaNaEmissao->value]);

        Livewire::test(EditFornecedor::class, ['record' => $fornecedor->getKey()])
            ->assertFormFieldDisabled('condicaoCompra.fcc_regra_baixa')
            ->assertFormFieldDisabled('condicaoCompra.fcc_entrada_automatica')
            ->fillForm(['condicaoCompra' => ['fcc_data_entrada_regra' => 'emissao']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(RegraBaixaCompra::AutomaticaNaEmissao, $fornecedor->fresh()->condicaoCompra->fcc_regra_baixa);
    }

    public function test_confirmar_compra_que_ja_tem_titulo_nao_gera_outro_e_refaz_o_rateio(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $plano = PlanoDespesa::create(['nome' => 'Insumos', 'comportamento' => Comportamento::Variavel, 'periodicidade' => Periodicidade::Eventual]);
        $diversos = PlanoDespesa::create(['nome' => 'Diversos', 'comportamento' => Comportamento::Variavel, 'periodicidade' => Periodicidade::Eventual]);
        $fornecedor = $this->fornecedor();
        $produto = Produto::create([
            'produto_descricao' => 'Farinha',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Insumos'])->id,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
        ]);
        $compra = Compra::create(['compra_prestador_id' => $fornecedor->id, 'compra_numero' => '77', 'compra_data_entrada' => today(), 'compra_user_id' => auth()->id()]);
        CompraItem::create(['ci_compra_id' => $compra->id, 'ci_produto_id' => $produto->id, 'ci_quantidade_compra' => 2, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 50]);
        app(CompraService::class)->recalcularTotais($compra->fresh('itens'));

        // Como a automação faz na importação: título no rascunho, com o plano padrão do fornecedor.
        $titulo = app(CompraService::class)->criarTitulos($compra->fresh(), [['vencimento' => today(), 'valor' => 100]], planoPadraoId: $diversos->id)->sole();
        $this->assertSame($diversos->id, $titulo->plano_despesa_id);
        $produto->update(['produto_plano_despesa_id' => $plano->id]);

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->mountAction('confirmar')
            ->assertActionDataSet(['gerar_conta_pagar' => false])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->fresh()->compra_status);
        $this->assertSame([$titulo->id], $compra->lancamentos()->pluck('id')->all());
        $this->assertSame($plano->id, $titulo->fresh()->plano_despesa_id);
    }

    public function test_tela_do_de_para_lista_e_editar_marca_como_confirmado_manualmente(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($admin);
        $fornecedor = $this->fornecedor();
        $produto = Produto::create([
            'produto_descricao' => 'Muçarela',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Insumos'])->id,
            'produto_tipo' => 'insumo',
        ]);
        $vinculo = FornecedorProduto::create([
            'fp_prestador_id' => $fornecedor->id, 'fp_produto_id' => $produto->id,
            'fp_codigo_fornecedor' => 'MUS-4KG', 'fp_fator_conversao' => 1, 'fp_origem' => OrigemVinculo::Ean,
        ]);

        Livewire::test(ManageFornecedorProdutos::class)
            ->assertCanSeeTableRecords([$vinculo])
            ->callAction(TestAction::make('edit')->table($vinculo), ['fp_fator_conversao' => 4])
            ->assertHasNoActionErrors();

        $vinculo->refresh();
        $this->assertEquals(4, $vinculo->fp_fator_conversao);
        $this->assertSame(OrigemVinculo::Manual, $vinculo->fp_origem);
        $this->assertSame($admin->id, $vinculo->fp_confirmado_por);
    }

    private function fornecedor(): Prestador
    {
        $fornecedor = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => 'Distribuidora X', 'nome' => 'Distribuidora X', 'cpf_cnpj' => '14200166000166']);
        FornecedorCondicaoCompra::create(['fcc_prestador_id' => $fornecedor->id, 'fcc_prazo_base' => 'entrada', 'fcc_data_entrada_regra' => 'hoje']);

        return $fornecedor;
    }
}
