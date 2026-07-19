<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre a exibição da hierarquia de categorias no cardápio público: uma
 * categoria filha (categoria_pai_id preenchido) deixa de ser seção própria e
 * passa a aparecer aninhada dentro da seção da categoria pai.
 */
class CardapioCategoriaHierarquiaTest extends TestCase
{
    use RefreshDatabase;

    private function produto(string $nome, int $categoriaId): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoriaId,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 20.00,
            'produto_cardapio' => true,
        ]);
    }

    public function test_categoria_filha_aparece_aninhada_na_secao_da_pai(): void
    {
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio' => true]);
        $refrigerantes = Categoria::create([
            'categoria_nome' => 'Refrigerantes',
            'categoria_cardapio' => true,
            'categoria_pai_id' => $bebidas->id,
        ]);
        $this->produto('Coca-Cola', $refrigerantes->id);

        $html = $this->get(route('cardapio'))->assertOk()->getContent();

        // Refrigerantes não vira seção própria (sem id="categoria_{filha}" fora
        // do bloco da pai) — aparece como subtítulo dentro da seção de Bebidas.
        $this->assertStringContainsString('Bebidas', $html);
        $this->assertStringContainsString('Refrigerantes', $html);
        $this->assertStringContainsString('Coca-Cola', $html);

        // A ordem no HTML confirma o aninhamento: Bebidas vem antes de Refrigerantes.
        $posBebidas = strpos($html, 'id="categoria_'.$bebidas->id.'"');
        $posRefrigerantes = strpos($html, 'id="categoria_'.$refrigerantes->id.'"');
        $this->assertNotFalse($posBebidas);
        $this->assertNotFalse($posRefrigerantes);
        $this->assertLessThan($posRefrigerantes, $posBebidas);
    }

    public function test_categoria_pai_sem_produto_proprio_ainda_mostra_produtos_da_filha(): void
    {
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio' => true]);
        $sucos = Categoria::create([
            'categoria_nome' => 'Sucos',
            'categoria_cardapio' => true,
            'categoria_pai_id' => $bebidas->id,
        ]);
        $this->produto('Suco de Laranja', $sucos->id);

        // Bebidas não tem produto direto nenhum, só via a filha Sucos.
        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('Bebidas')
            ->assertSee('Sucos')
            ->assertSee('Suco de Laranja');
    }

    public function test_categoria_pai_sem_produto_em_lugar_nenhum_nao_aparece(): void
    {
        Categoria::create(['categoria_nome' => 'Sobremesas', 'categoria_cardapio' => true]);

        $this->get(route('cardapio'))->assertOk()->assertDontSee('Sobremesas');
    }

    public function test_categoria_de_topo_sem_filha_continua_como_secao_normal(): void
    {
        $pizzas = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_cardapio' => true]);
        $this->produto('Calabresa', $pizzas->id);

        $this->get(route('cardapio'))->assertOk()->assertSee('Pizzas')->assertSee('Calabresa');
    }
}
