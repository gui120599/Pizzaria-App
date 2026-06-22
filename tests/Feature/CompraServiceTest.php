<?php

namespace Tests\Feature;

use App\Enums\CompraStatusEnum;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\FornecedorProduto;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\CompraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompraServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompraService $service;

    private int $categoriaId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CompraService::class);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
        $this->userId = User::factory()->create(['name_first' => 'Comprador'])->id;
    }

    private function insumo(string $nome, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
        ], $attrs));
    }

    private function fornecedor(): Prestador
    {
        return Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora X',
            'nome' => 'Distribuidora X',
            'cpf_cnpj' => '12345678000199',
        ]);
    }

    public function test_confirmar_gera_entradas_com_rateio_e_custo_medio(): void
    {
        $forn = $this->fornecedor();
        $queijo = $this->insumo('Queijo', ['produto_controla_lote' => true]);
        $oregano = $this->insumo('Orégano');

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_numero' => '1001',
            'compra_data_entrada' => now()->toDateString(),
            'compra_valor_frete' => 22,
            'compra_user_id' => $this->userId,
        ]);
        // 2 CX, 1 CX = 10 KG, custo 100/CX => 20 KG, valor 200
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $queijo->id, 'ci_codigo_fornecedor' => 'Q-01',
            'ci_descricao_fornecedor' => 'QUEIJO', 'ci_quantidade_compra' => 2, 'ci_unidade_compra' => 'CX',
            'ci_fator_conversao' => 10, 'ci_custo_unitario_compra' => 100,
            'ci_lote_codigo' => 'L1', 'ci_validade' => now()->addDays(30)->toDateString(),
        ]);
        // 5 KG, custo 4/KG => valor 20
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $oregano->id, 'ci_codigo_fornecedor' => 'O-01',
            'ci_quantidade_compra' => 5, 'ci_unidade_compra' => 'KG', 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 4,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $queijo->refresh();
        $oregano->refresh();
        // Frete 22 rateado: 200/220 -> 20 no queijo, 2 no orégano.
        $this->assertEqualsWithDelta(20.0, (float) $queijo->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(11.0, (float) $queijo->produto_custo_medio, 0.0001); // (200+20)/20
        $this->assertEqualsWithDelta(5.0, (float) $oregano->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(4.4, (float) $oregano->produto_custo_medio, 0.0001); // (20+2)/5

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertEqualsWithDelta(242.0, (float) $compra->compra_valor_total, 0.01);
        $this->assertEquals(2, $compra->movimentacoes()->count());

        // Lote do queijo vinculado ao item de compra.
        $this->assertNotNull($queijo->lotes()->first()->lote_compra_item_id);

        // De-para criado para ambos os itens.
        $this->assertEquals(2, FornecedorProduto::where('fp_prestador_id', $forn->id)->count());
    }

    public function test_nao_confirma_compra_ja_confirmada(): void
    {
        $forn = $this->fornecedor();
        $insumo = $this->insumo('Farinha');
        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 1, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 5,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $this->expectException(ValidationException::class);
        $this->service->confirmar($compra->fresh('itens'));
    }

    public function test_nao_confirma_item_sem_produto(): void
    {
        $compra = Compra::create([
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => null,
            'ci_quantidade_compra' => 1, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 5,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->confirmar($compra->fresh('itens'));
    }
}
