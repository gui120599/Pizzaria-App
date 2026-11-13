<?php

namespace Tests\Feature;

use App\Enums\BaseVencimentoEnum;
use App\Enums\Compras\OrigemVinculo;
use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\FormaPagamento;
use App\Enums\RegraDataEntradaEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Pages\PagamentosAConfirmar;
use App\Filament\Pages\RevisaoProdutosAutomacao;
use App\Filament\Resources\CompraPendencias\Pages\ListCompraPendencias;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\FornecedorCondicaoCompra;
use App\Models\FornecedorProduto;
use App\Models\Lancamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\Compras\CompraImportacaoService;
use App\Services\Compras\PendenciaService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ComprasAutomatizadasPainelTest extends TestCase
{
    use RefreshDatabase;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste LTDA', 'empresa_cnpj' => '11222333000181']);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Insumos'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_caixa_de_entrada_so_para_quem_resolve_pendencias(): void
    {
        $this->actingAs(User::factory()->create(['name_first' => 'Sem permissão']));
        $this->get('/admin/caixa-entrada-compras')->assertForbidden();

        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        Permission::firstOrCreate(['name' => 'resolver:pendencia_compra', 'guard_name' => 'web']);
        $gerente->givePermissionTo('resolver:pendencia_compra');
        $this->actingAs($gerente)->get('/admin/caixa-entrada-compras')->assertOk();
    }

    public function test_resolver_item_pela_caixa_de_entrada_grava_o_de_para(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $compra = $this->importar('item-desconhecido');
        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)
            ->whereHas('item', fn ($q) => $q->where('ci_codigo_fornecedor', 'EMB-35'))->sole();
        $caixa = $this->insumo('Caixa de pizza 35 cm');

        Livewire::test(ListCompraPendencias::class)
            ->assertCanSeeTableRecords([$pendencia])
            ->callAction(TestAction::make('resolverVinculo')->table($pendencia), ['produto_id' => $caixa->id, 'fator_conversao' => 1])
            ->assertHasNoActionErrors();

        $this->assertSame(StatusPendencia::Resolvida, $pendencia->fresh()->status);
        $this->assertSame(OrigemVinculo::Manual, FornecedorProduto::where('fp_codigo_fornecedor', 'EMB-35')->sole()->fp_origem);
    }

    public function test_resolver_mostra_os_dados_da_nota_e_preenche_o_produto_novo_com_eles(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $compra = $this->importar('item-desconhecido');
        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)
            ->whereHas('item', fn ($q) => $q->where('ci_codigo_fornecedor', 'EMB-35'))->sole();

        Livewire::test(ListCompraPendencias::class)
            ->mountAction(TestAction::make('resolverVinculo')->table($pendencia))
            ->assertMountedActionModalSee(['Item na nota fiscal', 'CAIXA PIZZA 35CM PARDA', '21039021', '5102', 'Distribuidora'])
            ->mountAction([
                TestAction::make('resolverVinculo')->table($pendencia),
                TestAction::make('createOption')->schemaComponent('produto_id', 'mountedActionSchema0'),
            ])
            ->assertActionDataSet([
                'produto_descricao' => 'CAIXA PIZZA 35CM PARDA',
                'produto_codigo_NCM' => '21039021',
                'produto_codigo_EAN' => null,
                'produto_codigo_CEST' => '0000000',
            ]);
    }

    public function test_aprovar_sugestoes_em_lote_aplica_so_as_sugeridas(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $compra = $this->importar('item-desconhecido');
        [$sugerida, $aberta] = $compra->pendencias()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)->orderBy('id')->get()->all();
        $catchup = $this->insumo('Catchup sachê');
        app(PendenciaService::class)->registrarSugestao($sugerida, ['acao' => 'vincular', 'produto_id' => $catchup->id, 'fator_conversao' => 200], 0.8, 'Mesma descrição.');

        Livewire::test(ListCompraPendencias::class)
            ->selectTableRecords([$sugerida->id, $aberta->id])
            ->callAction(TestAction::make('aprovarSugestoes')->table()->bulk())
            ->assertHasNoActionErrors();

        $this->assertSame(StatusPendencia::Resolvida, $sugerida->fresh()->status);
        $this->assertSame(StatusPendencia::Aberta, $aberta->fresh()->status);
        $vinculo = FornecedorProduto::where('fp_codigo_fornecedor', 'CAT-200')->sole();
        $this->assertSame(OrigemVinculo::Agente, $vinculo->fp_origem);
        $this->assertEquals(200, $vinculo->fp_fator_conversao);
    }

    public function test_escolher_lancamento_ambiguo_vincula_a_compra(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $fornecedor = $this->fornecedor('22333444000155', []);
        $this->dePara($fornecedor, 'MUS-4KG', 4);
        $this->dePara($fornecedor, 'ORE-1KG', 1);
        $primeiro = $this->lancamentoManual($fornecedor, '2026-11-12');
        $this->lancamentoManual($fornecedor, '2026-11-13');
        $compra = $this->importar('pix-a-vista');
        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::LancamentoAmbiguo)->sole();

        Livewire::test(ListCompraPendencias::class)
            ->callAction(TestAction::make('escolherLancamento')->table($pendencia), ['lancamento_id' => $primeiro->id])
            ->assertHasNoActionErrors();

        $this->assertSame($compra->id, $primeiro->fresh()->compra_id);
        $this->assertSame(StatusPendencia::Resolvida, $pendencia->fresh()->status);
    }

    public function test_pagamentos_a_confirmar_lista_parcelas_manuais_e_confirma_em_lote(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $banco = ContaFinanceira::factory()->banco()->comSaldo(5000)->create();
        $moinho = $this->fornecedor('33444555000166', [
            'fcc_regra_baixa' => RegraBaixaCompra::Manual,
            'fcc_conta_financeira_id' => $banco->id,
            'fcc_forma_pagamento' => FormaPagamento::Boleto,
        ]);
        $this->dePara($moinho, 'FAR-25', 25);
        $compra = $this->importar('boleto-3-duplicatas');
        $titulos = $compra->lancamentos()->orderBy('parcela_numero')->get();

        Livewire::test(PagamentosAConfirmar::class)
            ->removeTableFilters()
            ->assertCanSeeTableRecords($titulos)
            ->selectTableRecords([$titulos[0]->id, $titulos[1]->id])
            ->callAction(TestAction::make('confirmarEmLote')->table()->bulk(), [
                'data_pagamento' => '2026-11-12',
                'conta_financeira_id' => $banco->id,
                'forma_pagamento' => FormaPagamento::Boleto->value,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame([StatusLancamento::Pago, StatusLancamento::Pago, StatusLancamento::Pendente], $compra->lancamentos()->orderBy('parcela_numero')->pluck('status')->all());
        $this->assertSame(2, MovimentacaoFinanceira::where('conta_financeira_id', $banco->id)->count());
    }

    public function test_confirmar_com_juros_registra_o_valor_pago_e_resolve_a_pendencia_do_caixa(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $caixaPdv = ContaFinanceira::factory()->caixaPdv()->create();
        $banco = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();
        $fornecedor = $this->fornecedor('22333444000155', [
            'fcc_regra_baixa' => RegraBaixaCompra::AutomaticaNaEmissao,
            'fcc_conta_financeira_id' => $caixaPdv->id,
            'fcc_forma_pagamento' => FormaPagamento::Dinheiro,
        ]);
        $this->dePara($fornecedor, 'MUS-4KG', 4);
        $this->dePara($fornecedor, 'ORE-1KG', 1);
        $compra = $this->importar('pix-a-vista');
        $titulo = $compra->lancamentos()->sole();
        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::CaixaPdvFechado)->sole();

        Livewire::test(PagamentosAConfirmar::class)
            ->callAction(TestAction::make('confirmar')->table($titulo), [
                'data_pagamento' => '2026-11-12',
                'conta_financeira_id' => $banco->id,
                'forma_pagamento' => FormaPagamento::Pix->value,
                'valor' => '255,30',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(StatusLancamento::Pago, $titulo->fresh()->status);
        $this->assertEquals(744.70, $banco->fresh()->saldo_atual);
        $this->assertSame(StatusPendencia::Resolvida, $pendencia->fresh()->status);
    }

    public function test_revisao_de_produtos_aprova_e_libera_para_a_ficha(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $produto = $this->insumo('Produto do agente', ['produto_cadastro_pendente_revisao' => true, 'produto_origem_cadastro' => 'agente']);

        Livewire::test(RevisaoProdutosAutomacao::class)
            ->assertCanSeeTableRecords([$produto])
            ->callAction(TestAction::make('aprovar')->table($produto))
            ->assertHasNoActionErrors();

        $this->assertFalse($produto->fresh()->produto_cadastro_pendente_revisao);
    }

    public function test_aprovar_sugestao_de_produto_novo_cadastra_para_revisao_e_vincula(): void
    {
        $revisor = User::factory()->admin()->create(['name_first' => 'Admin']);
        $compra = $this->importar('item-desconhecido');
        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)
            ->whereHas('item', fn ($q) => $q->where('ci_codigo_fornecedor', 'EMB-35'))->sole();
        app(PendenciaService::class)->registrarSugestao($pendencia, [
            'acao' => 'novo_produto',
            'produto' => ['produto_descricao' => 'Caixa de pizza 35 cm', 'produto_unidade_estoque' => 'UN', 'produto_categoria_id' => $this->categoriaId],
            'fator_conversao' => 1,
        ], 0.95, 'Nenhum produto parecido.');

        app(PendenciaService::class)->aprovarSugestao($pendencia->fresh(), $revisor);

        $produto = Produto::where('produto_descricao', 'Caixa de pizza 35 cm')->sole();
        $this->assertTrue($produto->produto_cadastro_pendente_revisao);
        $this->assertSame('agente', $produto->produto_origem_cadastro);
        $this->assertSame($produto->id, $pendencia->item->fresh()->ci_produto_id);
    }

    // ------------------------------------------------------------------

    private function importar(string $fixture): Compra
    {
        return app(CompraImportacaoService::class)->importar(file_get_contents(base_path("tests/Fixtures/nfe-entrada/automacao/{$fixture}.xml")));
    }

    private function fornecedor(string $cnpj, array $condicao): Prestador
    {
        $fornecedor = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => "Fornecedor {$cnpj}", 'cpf_cnpj' => $cnpj]);
        FornecedorCondicaoCompra::create([
            'fcc_prestador_id' => $fornecedor->id,
            'fcc_prazo_base' => BaseVencimentoEnum::Entrada,
            'fcc_data_entrada_regra' => RegraDataEntradaEnum::Hoje,
            ...$condicao,
        ]);

        return $fornecedor;
    }

    private function dePara(Prestador $fornecedor, string $codigo, float $fator): void
    {
        FornecedorProduto::create([
            'fp_prestador_id' => $fornecedor->id,
            'fp_produto_id' => $this->insumo("Insumo {$codigo}")->id,
            'fp_codigo_fornecedor' => $codigo,
            'fp_fator_conversao' => $fator,
        ]);
    }

    private function insumo(string $nome, array $attrs = []): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'UN',
            ...$attrs,
        ]);
    }

    private function lancamentoManual(Prestador $fornecedor, string $vencimento): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'favorecido_id' => $fornecedor->id,
            'descricao' => "À mão {$vencimento}",
            'valor' => 250,
            'vencimento' => $vencimento,
            'status' => StatusLancamento::Pendente,
        ]);
    }
}
