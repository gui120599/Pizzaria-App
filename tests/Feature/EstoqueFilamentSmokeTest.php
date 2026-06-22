<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\CentroCustos\Pages\ManageCentroCustos;
use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Filament\Resources\Fornecedores\Pages\CreateFornecedor;
use App\Filament\Resources\Fornecedores\Pages\EditFornecedor;
use App\Filament\Resources\Fornecedores\Pages\ListFornecedores;
use App\Filament\Resources\Fornecedores\RelationManagers\FornecedorProdutosRelationManager;
use App\Filament\Resources\MovimentacaoProdutos\Pages\ManageMovimentacaoProdutos;
use App\Models\Prestador;
use App\Filament\Resources\Produtos\Pages\EditProduto;
use App\Filament\Resources\Produtos\Pages\ListProdutos;
use App\Filament\Resources\Produtos\RelationManagers\FichaItensRelationManager;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EstoqueFilamentSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name_first' => 'Admin']));
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

    public function test_lista_de_produtos_monta_com_colunas_e_acoes_de_estoque(): void
    {
        Livewire::test(ListProdutos::class)
            ->assertOk()
            ->assertTableColumnExists('produto_saldo_estoque')
            ->assertTableColumnExists('produto_custo_medio')
            ->assertTableActionExists('movimentar_estoque')
            ->assertTableActionExists('ajuste_estoque');
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

    public function test_relation_manager_da_ficha_tecnica_monta(): void
    {
        $produto = $this->produto();

        Livewire::test(FichaItensRelationManager::class, [
            'ownerRecord' => $produto,
            'pageClass' => EditProduto::class,
        ])->assertOk();
    }

    public function test_paginas_de_compras_montam(): void
    {
        Livewire::test(ListCompras::class)->assertOk();
        Livewire::test(CreateCompra::class)->assertOk();
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
