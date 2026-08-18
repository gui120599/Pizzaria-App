<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\Periodicidade;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraDevolucao;
use App\Models\CompraItem;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\PrestadorCredito;
use App\Models\Produto;
use App\Models\User;
use App\Services\CompraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrarDevolucaoCompraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function compraConfirmadaComItem(float $custoUnitario = 10.0, float $quantidade = 10.0): array
    {
        $categoria = Categoria::create(['categoria_nome' => 'Teste']);
        $plano = PlanoDespesa::create([
            'nome' => 'CMV / Insumos',
            'comportamento' => Comportamento::Variavel,
            'periodicidade' => Periodicidade::Eventual,
        ]);
        $forn = Prestador::create([
            'tipo' => 'pj', 'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora X', 'nome' => 'Distribuidora X',
        ]);
        $insumo = Produto::create([
            'produto_descricao' => 'Farinha',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
            'produto_plano_despesa_id' => $plano->id,
        ]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => auth()->id(),
        ]);
        $item = CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_descricao_fornecedor' => 'FARINHA', 'ci_quantidade_compra' => $quantidade,
            'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => $custoUnitario,
        ]);

        app(CompraService::class)->confirmar($compra->fresh('itens'));

        return [$compra->fresh(), $item->fresh(), $forn];
    }

    public function test_acao_so_aparece_para_compra_confirmada(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Teste']);
        $forn = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => 'X', 'nome' => 'X']);
        $rascunho = Compra::create(['compra_prestador_id' => $forn->id, 'compra_data_entrada' => now()->toDateString(), 'compra_user_id' => auth()->id()]);

        [$confirmada] = $this->compraConfirmadaComItem();

        $component = Livewire::test(ListCompras::class);
        $component->assertTableActionHidden('registrarDevolucao', $rascunho);
        $component->assertTableActionVisible('registrarDevolucao', $confirmada);
    }

    public function test_registrar_devolucao_pela_tabela_baixa_estoque_e_gera_credito(): void
    {
        [$compra, $item, $forn] = $this->compraConfirmadaComItem();

        Livewire::test(ListCompras::class)
            ->callTableAction('registrarDevolucao', $compra, data: [
                'itens' => [
                    ['compra_item_id' => $item->id, 'quantidade' => 3],
                ],
                'motivo' => 'Produto avariado',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, CompraDevolucao::where('compra_id', $compra->id)->count());

        $credito = PrestadorCredito::doPrestador($forn->id)->first();
        $this->assertNotNull($credito);
        $this->assertEqualsWithDelta(30.0, (float) $credito->valor, 0.01);
    }

    public function test_registrar_devolucao_pela_pagina_de_edicao(): void
    {
        [$compra, $item] = $this->compraConfirmadaComItem();

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->callAction('registrarDevolucao', data: [
                'itens' => [
                    ['compra_item_id' => $item->id, 'quantidade' => 2],
                ],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, CompraDevolucao::where('compra_id', $compra->id)->count());
    }

    public function test_devolucao_com_quantidade_acima_do_disponivel_nao_cria_nada(): void
    {
        [$compra, $item] = $this->compraConfirmadaComItem(quantidade: 10.0);

        Livewire::test(ListCompras::class)
            ->callTableAction('registrarDevolucao', $compra, data: [
                'itens' => [
                    ['compra_item_id' => $item->id, 'quantidade' => 99],
                ],
            ]);

        $this->assertSame(0, CompraDevolucao::where('compra_id', $compra->id)->count());
    }
}
