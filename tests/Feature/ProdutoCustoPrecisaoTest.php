<?php

namespace Tests\Feature;

use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Produto;
use App\Models\User;
use App\Services\EstoqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garante que custo unitário com até 8 casas decimais (ex.: água a
 * R$ 0,00950475/L) sobrevive à persistência em toda a cadeia de custo —
 * não é truncado pela coluna do banco nem pelos rounds intermediários.
 */
class ProdutoCustoPrecisaoTest extends TestCase
{
    use RefreshDatabase;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
    }

    public function test_produto_preco_custo_preserva_oito_casas_decimais(): void
    {
        $produto = Produto::create([
            'produto_descricao' => 'Água',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => ProdutoTipoEnum::INSUMO->value,
            'produto_preco_custo' => 0.00950475,
            'produto_unidade_estoque' => 'L',
        ]);

        $produto->refresh();

        $this->assertEqualsWithDelta(0.00950475, (float) $produto->produto_preco_custo, 0.000000001);
    }

    public function test_compra_item_custo_unitario_preserva_oito_casas_decimais(): void
    {
        $produto = Produto::create([
            'produto_descricao' => 'Água',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => ProdutoTipoEnum::INSUMO->value,
            'produto_controla_estoque' => true,
            'produto_unidade_estoque' => 'L',
        ]);

        $compra = Compra::create([]);

        $compraItem = CompraItem::create([
            'ci_compra_id' => $compra->id,
            'ci_produto_id' => $produto->id,
            'ci_descricao_fornecedor' => 'Água',
            'ci_quantidade_compra' => 40,
            'ci_unidade_compra' => 'M3',
            'ci_fator_conversao' => 1000,
            'ci_custo_unitario_compra' => 0.00950475,
            'ci_valor_rateio' => 0,
        ]);

        $compraItem->refresh();

        $this->assertEqualsWithDelta(0.00950475, (float) $compraItem->ci_custo_unitario_compra, 0.000000001);
    }

    public function test_registrar_entrada_preserva_precisao_do_custo_medio_wac(): void
    {
        $produto = Produto::create([
            'produto_descricao' => 'Água',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => ProdutoTipoEnum::INSUMO->value,
            'produto_controla_estoque' => true,
            'produto_unidade_estoque' => 'L',
        ]);

        $userId = User::factory()->create(['name_first' => 'Operador'])->id;

        app(EstoqueService::class)->registrarEntrada(
            $produto,
            40000, // 40 m3 convertidos para litros
            0.00950475,
            MovimentacaoOrigemEnum::COMPRA,
            ['user_id' => $userId],
        );

        $produto->refresh();

        $this->assertEqualsWithDelta(0.00950475, (float) $produto->produto_custo_medio, 0.000000001);
    }
}
