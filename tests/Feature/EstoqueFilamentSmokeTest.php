<?php

namespace Tests\Feature;

use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\CustoMedioHistorico;
use App\Filament\Resources\CentroCustos\Pages\ManageCentroCustos;
use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Filament\Resources\Compras\RelationManagers\ItensRelationManager;
use App\Filament\Resources\Fornecedores\Pages\CreateFornecedor;
use App\Filament\Resources\Fornecedores\Pages\EditFornecedor;
use App\Filament\Resources\Fornecedores\Pages\ListFornecedores;
use App\Filament\Resources\Fornecedores\RelationManagers\FornecedorProdutosRelationManager;
use App\Filament\Resources\Marcas\Pages\ManageMarcas;
use App\Filament\Resources\MovimentacaoProdutos\Pages\ManageMovimentacaoProdutos;
use App\Filament\Resources\Produtos\Pages\CreateProduto;
use App\Filament\Resources\Produtos\Pages\EditProduto;
use App\Filament\Resources\Produtos\Pages\ListProdutos;
use App\Filament\Resources\Produtos\RelationManagers\FichaItensRelationManager;
use App\Filament\Resources\Produtos\RelationManagers\LotesRelationManager;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\EstoqueLote;
use App\Models\FichaTecnicaItem;
use App\Models\Marca;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\EstoqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EstoqueFilamentSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function produto(): Produto
    {
        return Produto::create([
            'produto_descricao' => 'Insumo Teste',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Teste'])->id,
            'produto_tipo' => ProdutoTipoEnum::INSUMO->value,
            'produto_controla_estoque' => true,
        ]);
    }

    public function test_lista_de_movimentacoes_monta_sem_erro(): void
    {
        Livewire::test(ManageMovimentacaoProdutos::class)->assertOk();
    }

    public function test_lista_de_centros_de_custo_monta_sem_erro(): void
    {
        Livewire::test(ManageCentroCustos::class)->assertOk();
    }

    public function test_pagina_de_custo_medio_historico_monta_e_consulta(): void
    {
        $produto = $this->produto();
        app(EstoqueService::class)->registrarEntrada($produto, 10, 4, MovimentacaoOrigemEnum::COMPRA);

        Livewire::test(CustoMedioHistorico::class)
            ->assertOk()
            ->assertSee('Produto')
            ->assertSee('Custo médio na data')
            ->fillForm(['produto_id' => $produto->id, 'data' => now()->toDateString()])
            ->assertOk()
            ->assertSee('R$ 4,0000');
    }

    public function test_lista_de_produtos_monta_com_colunas_e_acoes_de_estoque(): void
    {
        Livewire::test(ListProdutos::class)
            ->assertOk()
            ->assertTableColumnExists('produto_saldo_estoque')
            ->assertTableColumnExists('produto_custo_medio')
            ->assertTableActionExists('movimentar_estoque')
            // Removidas: ajuste de inventário e ajuste de preço promocional por linha.
            ->assertTableActionDoesNotExist('ajuste_estoque')
            ->assertTableActionDoesNotExist('ajustar_preco_promocional');
    }

    public function test_acao_movimentar_registra_entrada_via_estoque_service(): void
    {
        $produto = $this->produto();

        Livewire::test(ListProdutos::class)
            ->callTableAction('movimentar_estoque', $produto, data: [
                'tipo' => 'entrada',
                'origem' => 'compra',
                'quantidade' => 10,
                'custo_unitario' => 2.50,
            ])
            ->assertHasNoTableActionErrors();

        $produto->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $produto->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(2.5, (float) $produto->produto_custo_medio, 0.0001);
        $this->assertDatabaseHas('movimentacao_produtos', [
            'mov_produto_id' => $produto->id,
            'mov_tipo' => 'ENTRADA',
            'mov_origem' => 'compra',
        ]);
    }

    public function test_acao_registrar_producao_gera_lote_com_validade_para_insumo_produzido(): void
    {
        $categoriaId = Categoria::create(['categoria_nome' => 'Teste Produção'])->id;

        $farinha = Produto::create([
            'produto_descricao' => 'Farinha',
            'produto_categoria_id' => $categoriaId,
            'produto_tipo' => ProdutoTipoEnum::INSUMO->value,
            'produto_controla_estoque' => true,
        ]);

        $massa = Produto::create([
            'produto_descricao' => 'Massa',
            'produto_categoria_id' => $categoriaId,
            'produto_tipo' => ProdutoTipoEnum::INSUMO_PRODUZIDO->value,
            'produto_controla_estoque' => true,
            'produto_controla_lote' => true,
            'produto_ficha_rendimento' => 10,
        ]);
        FichaTecnicaItem::create([
            'fti_produto_id' => $massa->id,
            'fti_insumo_id' => $farinha->id,
            'fti_quantidade' => 1,
            'fti_percentual_perda' => 0,
        ]);

        app(EstoqueService::class)->registrarEntrada(
            $farinha,
            10,
            5.00,
            MovimentacaoOrigemEnum::COMPRA,
        );

        Livewire::test(EditProduto::class, ['record' => $massa->getRouteKey()])
            ->callAction('registrarProducao', data: [
                'quantidade_produzida' => 10,
                'lote_codigo' => 'M-UI-1',
                'validade' => '2026-09-01',
                'observacao' => 'Sova da manhã',
            ])
            ->assertHasNoActionErrors();

        $massa->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $massa->produto_saldo_estoque, 0.001);

        $lote = $massa->lotes()->where('lote_codigo', 'M-UI-1')->first();
        $this->assertNotNull($lote, 'Lote não foi criado a partir da action da UI.');
        $this->assertEqualsWithDelta(10.0, (float) $lote->lote_qtd_atual, 0.001);
        $this->assertSame('2026-09-01', $lote->lote_validade->toDateString());
    }

    public function test_acao_movimentar_entrada_com_marca_cria_lote_com_marca(): void
    {
        $produto = $this->produto();
        $produto->update(['produto_controla_lote' => true]);
        $marca = Marca::create(['marca_nome' => 'Sadia']);

        Livewire::test(ListProdutos::class)
            ->callTableAction('movimentar_estoque', $produto, data: [
                'tipo' => 'entrada',
                'origem' => 'compra',
                'quantidade' => 10,
                'custo_unitario' => 2.50,
                'lote_codigo' => 'L-MOV-1',
                'marca_id' => $marca->id,
                'validade' => '2026-09-01',
            ])
            ->assertHasNoTableActionErrors();

        $lote = $produto->lotes()->where('lote_codigo', 'L-MOV-1')->first();
        $this->assertNotNull($lote);
        $this->assertSame('Sadia', $lote->marca->marca_nome);
        $this->assertSame('2026-09-01', $lote->lote_validade->toDateString());
    }

    public function test_acao_movimentar_entrada_com_apenas_controla_marca_dispensa_lote_e_validade(): void
    {
        $produto = $this->produto();
        $produto->update(['produto_controla_marca' => true]);
        $marca = Marca::create(['marca_nome' => 'Scala']);

        Livewire::test(ListProdutos::class)
            ->callTableAction('movimentar_estoque', $produto, data: [
                'tipo' => 'entrada',
                'origem' => 'compra',
                'quantidade' => 10,
                'custo_unitario' => 3.00,
                'marca_id' => $marca->id,
            ])
            ->assertHasNoTableActionErrors();

        $lote = $produto->lotes()->first();
        $this->assertNotNull($lote);
        $this->assertSame('Scala', $lote->marca->marca_nome);
        $this->assertNull($lote->lote_codigo);
        $this->assertNull($lote->lote_validade);
    }

    public function test_relation_manager_de_lotes_e_visivel_para_produto_que_so_controla_marca(): void
    {
        $produto = $this->produto();
        $produto->update(['produto_controla_marca' => true]);

        $this->assertTrue(LotesRelationManager::canViewForRecord($produto, EditProduto::class));
    }

    public function test_acao_realizar_balanco_com_sobra_e_lote_cria_lote_com_marca(): void
    {
        $produto = $this->produto();
        $produto->update(['produto_controla_lote' => true, 'produto_saldo_estoque' => 5]);
        $marca = Marca::create(['marca_nome' => 'Perdigão']);

        Livewire::test(EditProduto::class, ['record' => $produto->getRouteKey()])
            ->callAction('realizarBalanco', data: [
                'quantidade_fisica' => 12,
                'lote_codigo' => 'L-BAL-UI',
                'marca_id' => $marca->id,
                'validade' => '2026-08-15',
                'observacao' => 'Sobra na contagem',
            ])
            ->assertHasNoActionErrors();

        $lote = $produto->lotes()->where('lote_codigo', 'L-BAL-UI')->first();
        $this->assertNotNull($lote);
        $this->assertSame('Perdigão', $lote->marca->marca_nome);
        $this->assertSame('2026-08-15', $lote->lote_validade->toDateString());
    }

    public function test_bulk_balanco_em_lote_aplica_marca_e_lote_por_item(): void
    {
        $produto = $this->produto();
        $produto->update(['produto_controla_lote' => true, 'produto_saldo_estoque' => 3]);
        $marca = Marca::create(['marca_nome' => 'Tirolez']);

        Livewire::test(ListProdutos::class)
            ->callTableBulkAction('balanco_em_lote', [$produto], data: [
                'itens' => [
                    [
                        'produto_id' => $produto->id,
                        'quantidade_contada' => 9,
                        'controla_lote' => true,
                        'rastreia_lote' => true,
                        'lote_codigo' => 'L-BULK-1',
                        'marca_id' => $marca->id,
                        'validade' => '2026-10-01',
                    ],
                ],
            ])
            ->assertHasNoTableBulkActionErrors();

        $lote = $produto->lotes()->where('lote_codigo', 'L-BULK-1')->first();
        $this->assertNotNull($lote);
        $this->assertSame('Tirolez', $lote->marca->marca_nome);
        $this->assertSame('2026-10-01', $lote->lote_validade->toDateString());
    }

    public function test_bulk_balanco_em_lote_permite_marca_sem_lote_para_produto_que_so_controla_marca(): void
    {
        $produto = $this->produto();
        $produto->update(['produto_controla_marca' => true, 'produto_saldo_estoque' => 3]);
        $marca = Marca::create(['marca_nome' => 'Scala']);

        Livewire::test(ListProdutos::class)
            ->callTableBulkAction('balanco_em_lote', [$produto], data: [
                'itens' => [
                    [
                        'produto_id' => $produto->id,
                        'quantidade_contada' => 9,
                        'controla_lote' => false,
                        'rastreia_lote' => true,
                        'lote_codigo' => null,
                        'marca_id' => $marca->id,
                        'validade' => null,
                    ],
                ],
            ])
            ->assertHasNoTableBulkActionErrors();

        $lote = $produto->lotes()->first();
        $this->assertNotNull($lote);
        $this->assertSame('Scala', $lote->marca->marca_nome);
        $this->assertNull($lote->lote_codigo);
    }

    public function test_form_de_produto_em_abas_monta(): void
    {
        Livewire::test(CreateProduto::class)->assertOk();
    }

    public function test_relation_manager_da_ficha_tecnica_monta(): void
    {
        $produto = $this->produto();

        Livewire::test(FichaItensRelationManager::class, [
            'ownerRecord' => $produto,
            'pageClass' => EditProduto::class,
        ])->assertOk();
    }

    public function test_relation_manager_da_ficha_tecnica_mostra_categoria_e_lote_a_debitar(): void
    {
        $pizza = $this->produto();
        $categoriaMussarela = Categoria::create(['categoria_nome' => 'Laticínios'])->id;

        $mussarela = Produto::create([
            'produto_descricao' => 'Muçarela',
            'produto_categoria_id' => $categoriaMussarela,
            'produto_tipo' => ProdutoTipoEnum::INSUMO->value,
            'produto_controla_estoque' => true,
            'produto_controla_lote' => true,
        ]);
        $lote = EstoqueLote::create([
            'lote_produto_id' => $mussarela->id,
            'lote_codigo' => 'L-001',
            'lote_validade' => now()->addDays(5)->toDateString(),
            'lote_qtd_inicial' => 10,
            'lote_qtd_atual' => 10,
            'lote_custo_unitario' => 3.00,
            'lote_data_entrada' => now(),
            'lote_status' => 'ativo',
        ]);
        $itemComLote = FichaTecnicaItem::create([
            'fti_produto_id' => $pizza->id,
            'fti_insumo_id' => $mussarela->id,
            'fti_quantidade' => 0.2,
            'fti_percentual_perda' => 0,
        ]);

        $farinha = Produto::create([
            'produto_descricao' => 'Farinha',
            'produto_categoria_id' => $categoriaMussarela,
            'produto_tipo' => ProdutoTipoEnum::INSUMO->value,
            'produto_controla_estoque' => true,
        ]);
        $itemSemLote = FichaTecnicaItem::create([
            'fti_produto_id' => $pizza->id,
            'fti_insumo_id' => $farinha->id,
            'fti_quantidade' => 0.3,
            'fti_percentual_perda' => 0,
        ]);

        $loteEsperado = "L-001 (val. {$lote->lote_validade->format('d/m/Y')})";

        Livewire::test(FichaItensRelationManager::class, [
            'ownerRecord' => $pizza,
            'pageClass' => EditProduto::class,
        ])
            ->assertOk()
            ->assertTableColumnHasDescription('insumo.produto_descricao', 'Laticínios', $itemComLote, position: 'above')
            ->assertTableColumnHasDescription('insumo.produto_descricao', $loteEsperado, $itemComLote, position: 'below')
            ->assertTableColumnHasDescription('insumo.produto_descricao', 'Laticínios', $itemSemLote, position: 'above')
            ->assertTableColumnHasDescription('insumo.produto_descricao', null, $itemSemLote, position: 'below');
    }

    public function test_relation_manager_de_lotes_mostra_marca_e_status_vencido_derivado(): void
    {
        $produto = $this->produto();
        $produto->update(['produto_controla_lote' => true]);
        $sadia = Marca::create(['marca_nome' => 'Sadia']);
        $perdigao = Marca::create(['marca_nome' => 'Perdigão']);

        $ativo = EstoqueLote::create([
            'lote_produto_id' => $produto->id,
            'lote_codigo' => 'L-ATIVO',
            'lote_marca_id' => $sadia->id,
            'lote_validade' => now()->addDays(10)->toDateString(),
            'lote_qtd_inicial' => 5,
            'lote_qtd_atual' => 5,
            'lote_custo_unitario' => 2.5,
            'lote_data_entrada' => now(),
            'lote_status' => 'ativo',
        ]);

        $vencido = EstoqueLote::create([
            'lote_produto_id' => $produto->id,
            'lote_codigo' => 'L-VENCIDO',
            'lote_marca_id' => $perdigao->id,
            'lote_validade' => now()->subDays(3)->toDateString(),
            'lote_qtd_inicial' => 3,
            'lote_qtd_atual' => 3,
            'lote_custo_unitario' => 2.5,
            'lote_data_entrada' => now()->subDays(20),
            'lote_status' => 'ativo',
        ]);

        Livewire::test(LotesRelationManager::class, [
            'ownerRecord' => $produto,
            'pageClass' => EditProduto::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$ativo, $vencido])
            ->assertTableColumnStateSet('lote_status', 'ativo', $ativo)
            ->assertTableColumnStateSet('lote_status', 'vencido', $vencido);
    }

    public function test_marca_resource_lista_e_cria_marca(): void
    {
        Livewire::test(ManageMarcas::class)
            ->assertOk()
            ->callAction('create', data: [
                'marca_nome' => 'Sadia',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('marcas', ['marca_nome' => 'Sadia']);
    }

    public function test_paginas_de_compras_montam(): void
    {
        Livewire::test(ListCompras::class)->assertOk();
        Livewire::test(CreateCompra::class)->assertOk();
    }

    public function test_relation_manager_de_itens_da_compra_monta(): void
    {
        $compra = Compra::create([
            'compra_data_entrada' => now()->toDateString(),
            'compra_status' => 'rascunho',
        ]);

        Livewire::test(ItensRelationManager::class, [
            'ownerRecord' => $compra,
            'pageClass' => EditCompra::class,
        ])->assertOk();
    }

    public function test_paginas_de_fornecedores_e_depara_montam(): void
    {
        Livewire::test(ListFornecedores::class)->assertOk();
        Livewire::test(CreateFornecedor::class)->assertOk();

        $fornecedor = Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora X',
            'nome' => 'Distribuidora X',
            'cpf_cnpj' => '12345678000199',
        ]);

        Livewire::test(FornecedorProdutosRelationManager::class, [
            'ownerRecord' => $fornecedor,
            'pageClass' => EditFornecedor::class,
        ])->assertOk();
    }
}
