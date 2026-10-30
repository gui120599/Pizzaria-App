<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cardápio público redesenhado: layout próprio, preço exibido igual ao
 * cobrado (precoResolvido), dados de exibição do detalhe, ingredientes na
 * montagem da pizza e o link antigo /Produto/{id}.
 */
class CardapioRedesignTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'categoria_nome' => 'Pizza Grande',
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

    public function test_cardapio_usa_o_layout_com_nome_da_pizzaria_e_imagem_de_compartilhamento(): void
    {
        $this->produto('Calabresa');

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('<title>Empório da Pizza · Cardápio</title>', false)
            ->assertSee(asset('img/marca/og-cardapio.jpg'), false);
    }

    public function test_card_mostra_o_preco_cobrado_com_o_anterior_riscado_e_a_economia(): void
    {
        $this->produto('Calabresa', ['produto_preco_promocional' => 45.00]);

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSeeInOrder(['R$ 45,00', 'R$ 55,00', '−R$ 10,00']);
    }

    public function test_dentro_da_categoria_o_card_mostra_so_a_descricao_e_o_carrinho_recebe_o_nome_completo(): void
    {
        $produto = $this->produto('Calabresa', ['produto_exibe_categoria' => true]);

        $html = $this->get(route('cardapio'))->assertOk()->getContent();

        $this->assertStringContainsString('<h3 class="card-titulo">Calabresa</h3>', $html);
        $this->assertStringContainsString("\$store.cart.add({$produto->id}, 'Pizza Grande Calabresa'", $html);
    }

    public function test_detalhe_do_produto_recebe_os_dados_de_exibicao(): void
    {
        $produto = $this->produto('Calabresa', ['produto_codimentacao' => 'Molho, muçarela e calabresa']);

        $detalhe = $this->get(route('cardapio'))->assertOk()->viewData('produtosTela')[$produto->id]->paraTela();

        $this->assertSame('Calabresa', $detalhe['nome']);
        $this->assertSame('Molho, muçarela e calabresa', $detalhe['descricao']);
        $this->assertSame(55.0, $detalhe['preco']);
        $this->assertNull($detalhe['foto']);
    }

    public function test_produto_sem_foto_usa_o_monograma_em_vez_da_imagem_padrao(): void
    {
        $this->produto('Calabresa');

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('Calabresa (sem foto)')
            ->assertDontSee('Sem Imagem.png');
    }

    public function test_sabores_levam_os_ingredientes_para_a_montagem_da_pizza(): void
    {
        $this->categoria->update(['categoria_permite_sabores' => true]);
        $this->categoria->sincronizarQuantidadesSabores(2);
        $this->produto('Calabresa', ['produto_codimentacao' => 'Molho, muçarela e calabresa']);
        $this->produto('Marguerita');

        $sabores = $this->get(route('cardapio'))->assertOk()->viewData('categoriasComSabores')->first()['produtos'];

        $this->assertSame('Molho, muçarela e calabresa', $sabores->firstWhere('nome', 'Calabresa')['ingredientes']);
        $this->assertNull($sabores->firstWhere('nome', 'Marguerita')['ingredientes']);
    }

    public function test_link_antigo_do_produto_abre_o_detalhe_dentro_do_cardapio(): void
    {
        $produto = $this->produto('Calabresa');

        $this->get(route('produto.show', $produto))
            ->assertRedirect(route('cardapio', ['produto' => $produto->id]));
    }

    public function test_link_antigo_de_produto_fora_do_cardapio_nao_existe(): void
    {
        $produto = $this->produto('Fermento', ['produto_cardapio' => false]);

        $this->get(route('produto.show', $produto))->assertNotFound();
    }
}
