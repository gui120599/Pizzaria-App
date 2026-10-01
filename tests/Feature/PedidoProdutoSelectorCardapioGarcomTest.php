<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Produto oculto do cardápio do garçom/atendente (produto_cardapio_garcom)
 * some da tela de pedidos — grade, lista de sabores e, se for o último
 * visível, a própria categoria.
 */
class PedidoProdutoSelectorCardapioGarcomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function produto(Categoria $categoria, string $nome, bool $cardapioGarcom = true): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.0,
            'produto_foto' => 'produtos/foto.jpg',
            'produto_cardapio_garcom' => $cardapioGarcom,
        ]);
    }

    public function test_produto_oculto_nao_aparece_na_grade(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio_garcom' => true]);
        $visivel = $this->produto($categoria, 'Refrigerante');
        $oculto = $this->produto($categoria, 'Água', cardapioGarcom: false);

        $produtos = Livewire::test(PedidoProdutoSelector::class)->instance()->produtos();

        $this->assertTrue($produtos->contains('id', $visivel->id));
        $this->assertFalse($produtos->contains('id', $oculto->id));
    }

    public function test_categoria_com_todos_os_produtos_ocultos_nao_aparece(): void
    {
        $soOcultos = Categoria::create(['categoria_nome' => 'Sobremesas', 'categoria_cardapio_garcom' => true]);
        $this->produto($soOcultos, 'Pudim', cardapioGarcom: false);
        $comVisivel = Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio_garcom' => true]);
        $this->produto($comVisivel, 'Refrigerante');

        $categorias = Livewire::test(PedidoProdutoSelector::class)->instance()->categorias();

        $this->assertFalse($categorias->contains('id', $soOcultos->id));
        $this->assertTrue($categorias->contains('id', $comVisivel->id));
    }

    public function test_sabor_oculto_nao_aparece_no_modal_de_sabores(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_permite_sabores' => true, 'categoria_cardapio_garcom' => true]);
        $calabresa = $this->produto($categoria, 'Calabresa');
        $oculta = $this->produto($categoria, 'Atum', cardapioGarcom: false);

        $saboresProdutos = Livewire::test(PedidoProdutoSelector::class)
            ->call('selecionarProduto', $calabresa->id)
            ->get('saboresProdutos');

        $ids = collect($saboresProdutos)->pluck('id');
        $this->assertTrue($ids->contains($calabresa->id));
        $this->assertFalse($ids->contains($oculta->id));
    }
}
