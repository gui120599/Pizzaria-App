<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\VendaProdutoSelector;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * VendaProdutoSelector é puramente um catálogo/busca — não persiste nada e
 * não conhece a Venda atual. A persistência de fato (e a criação lazy da
 * Venda) mora em App\Filament\Pages\OperarVenda::adicionarProdutoAvulso,
 * acionado pelo evento `produto-selecionado` disparado aqui — ver
 * OperarVendaProdutosTest.
 */
class VendaProdutoSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $categoria = Categoria::create(['categoria_nome' => 'Bebidas']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Refrigerante Lata',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_preco_venda' => 8.00,
        ]);
    }

    public function test_clicar_no_produto_dispara_evento_produto_selecionado(): void
    {
        Livewire::test(VendaProdutoSelector::class)
            ->call('selecionarProduto', $this->produto->id)
            ->assertDispatched('produto-selecionado', produtoId: $this->produto->id);
    }

    public function test_filtra_produtos_por_busca(): void
    {
        Produto::create([
            'produto_descricao' => 'Água Mineral',
            'produto_categoria_id' => $this->produto->produto_categoria_id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_preco_venda' => 4.00,
        ]);

        $component = Livewire::test(VendaProdutoSelector::class)
            ->set('busca', 'Refrigerante');

        $nomes = $component->instance()->produtos->pluck('produto_descricao')->all();

        $this->assertSame(['Refrigerante Lata'], $nomes);
    }
}
