<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre Produto::scopeVisivelCardapio() (usado pelo CardapioController): um
 * produto com estoque controlado e saldo zerado só continua no cardápio
 * público se produto_lista_estoque_zerado permitir.
 */
class CardapioEstoqueVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'categoria_nome' => 'Pizzas',
            'categoria_cardapio' => true,
        ]);
    }

    private function produto(string $nome, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
            'produto_cardapio' => true,
        ], $attrs));
    }

    public function test_produto_sem_controle_de_estoque_aparece_mesmo_zerado(): void
    {
        $this->produto('Calabresa', [
            'produto_controla_estoque' => false,
            'produto_saldo_estoque' => 0,
        ]);

        $this->get(route('cardapio'))->assertOk()->assertSee('Calabresa');
    }

    public function test_produto_controlado_com_saldo_positivo_aparece(): void
    {
        $this->produto('Calabresa', [
            'produto_controla_estoque' => true,
            'produto_saldo_estoque' => 5,
        ]);

        $this->get(route('cardapio'))->assertOk()->assertSee('Calabresa');
    }

    public function test_produto_zerado_aparece_quando_lista_estoque_zerado_e_true(): void
    {
        $this->produto('Calabresa', [
            'produto_controla_estoque' => true,
            'produto_saldo_estoque' => 0,
            'produto_lista_estoque_zerado' => true,
        ]);

        $this->get(route('cardapio'))->assertOk()->assertSee('Calabresa');
    }

    public function test_produto_zerado_some_quando_lista_estoque_zerado_e_false(): void
    {
        $this->produto('Calabresa', [
            'produto_controla_estoque' => true,
            'produto_saldo_estoque' => 0,
            'produto_lista_estoque_zerado' => false,
        ]);

        $this->get(route('cardapio'))->assertOk()->assertDontSee('Calabresa');
    }
}
