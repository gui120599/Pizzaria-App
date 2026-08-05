<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\CompraStatusEnum;
use App\Enums\Periodicidade;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConfirmarCompraContaPagarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function compraComItem(): Compra
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
            'compra_numero' => '3003',
            'compra_data_entrada' => '2026-07-08',
            'compra_user_id' => auth()->id(),
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 4, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 25,
        ]);

        return $compra;
    }

    public function test_acao_da_tabela_confirma_e_gera_conta_a_pagar(): void
    {
        $compra = $this->compraComItem();

        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'gerar_conta_pagar' => true,
                'vencimento' => '2026-08-07',
            ])
            ->assertHasNoTableActionErrors();

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);

        $lancamento = $compra->contaPagar;
        $this->assertNotNull($lancamento);
        $this->assertSame(TipoLancamento::Pagar, $lancamento->tipo);
        $this->assertSame(StatusLancamento::Pendente, $lancamento->status);
        $this->assertEqualsWithDelta(100.0, (float) $lancamento->valor, 0.01); // 4 * 25
        $this->assertSame('2026-08-07', $lancamento->vencimento->toDateString());
        $this->assertCount(1, $lancamento->despesas);
    }

    public function test_acao_da_pagina_de_edicao_confirma_e_gera_conta_a_pagar(): void
    {
        $compra = $this->compraComItem();

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->callAction('confirmar', data: [
                'gerar_conta_pagar' => true,
                'vencimento' => '2026-08-10',
            ])
            ->assertHasNoActionErrors();

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertNotNull($compra->contaPagar);
        $this->assertSame('2026-08-10', $compra->contaPagar->vencimento->toDateString());
    }

    public function test_pode_confirmar_sem_gerar_conta_a_pagar(): void
    {
        $compra = $this->compraComItem();

        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'gerar_conta_pagar' => false,
            ])
            ->assertHasNoTableActionErrors();

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertNull($compra->contaPagar);
    }
}
