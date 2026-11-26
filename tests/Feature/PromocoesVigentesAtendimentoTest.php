<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\OpcoesEntregas;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoCombo;
use App\Models\PromocaoRelampago;
use App\Models\User;
use App\Services\PromocoesVigentesAtendimentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aba "Promoções" do catálogo no Atender Pedido: lista as promoções vigentes
 * (relâmpago, produto adicional e combo) com as regras resumidas, e o produto
 * tocado segue o fluxo normal de lançamento.
 */
class PromocoesVigentesAtendimentoTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $pizzas;

    private Produto $calabresa;

    private Produto $frango;

    private Produto $refri;

    private Produto $brotinho;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->pizzas = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio_garcom' => true]);
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio_garcom' => true]);
        $this->calabresa = $this->produto('Calabresa', 59.90, $this->pizzas);
        $this->frango = $this->produto('Frango', 50.10, $this->pizzas);
        $this->refri = $this->produto('Coca-Cola 2L', 16.00, $bebidas);
        $this->brotinho = $this->produto('Brotinho', 30.00, $bebidas);
    }

    private function produto(string $nome, float $preco, Categoria $categoria, bool $noGarcom = true): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio_garcom' => $noGarcom,
        ]);
    }

    private function relampago(array $attrs = []): PromocaoRelampago
    {
        $promocao = PromocaoRelampago::create($attrs + [
            'promocao_nome' => 'Relâmpago Noite',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 20,
            'promocao_qtd_vendida' => 5,
            'promocao_limite_por_pedido' => 2,
        ]);
        $promocao->promocaoProdutos()->create(['prp_produto_id' => $this->frango->id, 'prp_preco_promocional' => 39.90]);

        return $promocao;
    }

    private function adicional(): PromocaoAdicional
    {
        $promocao = PromocaoAdicional::create([
            'promoad_nome' => 'Refri + Brotinho grátis',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
            'promoad_categoria_exigida_id' => $this->pizzas->id,
        ]);
        $promocao->regras()->create(['par_produto_gatilho_id' => $this->refri->id])
            ->ofertas()->create(['pao_produto_oferta_id' => $this->brotinho->id, 'pao_valor_adicional' => 0]);

        return $promocao;
    }

    public function test_lista_relampago_adicional_e_combo_vigentes_com_as_regras(): void
    {
        $retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $this->relampago(['promocao_opcoes_entrega' => [$retirada->id]]);
        $this->adicional();
        PromocaoCombo::factory()->daCategoria($this->pizzas)->create();

        $cartoes = collect(app(PromocoesVigentesAtendimentoService::class)->listar())->keyBy('tipo');

        $this->assertSame(['relampago', 'adicional', 'combo'], $cartoes->keys()->all());

        $relampago = $cartoes['relampago'];
        $this->assertSame(['Máx. 2 por pedido', 'Restam 15', 'Só Retirada'], $relampago['restricoes']);
        $this->assertSame(
            [['id' => $this->frango->id, 'categoria' => 'Pizza Grande', 'preco' => 39.9, 'preco_original' => 50.1]],
            array_map(fn (array $p) => array_intersect_key($p, array_flip(['id', 'categoria', 'preco', 'preco_original'])), $relampago['produtos']),
        );

        $adicional = $cartoes['adicional'];
        $this->assertSame(['Exige 1 Pizza Grande no pedido', 'Máx. 1 por pedido'], $adicional['restricoes']);
        $this->assertSame([['nome' => 'Brotinho', 'valor' => 0.0]], $adicional['brindes']);
        $this->assertSame([$this->refri->id], array_column($adicional['produtos'], 'id'));

        $combo = $cartoes['combo'];
        $this->assertSame('2× Itens da promoção por R$ 87,99', $combo['destaque']);
        $this->assertEqualsCanonicalizing([$this->calabresa->id, $this->frango->id], array_column($combo['produtos'], 'id'));
    }

    public function test_omite_promocao_encerrada_ou_esgotada_e_produto_fora_do_cardapio_do_garcom(): void
    {
        $this->relampago(['promocao_fim' => now()->subMinute()]);
        $esgotada = $this->relampago(['promocao_nome' => 'Esgotada', 'promocao_qtd_vendida' => 20]);
        $foraDoGarcom = $this->produto('Pizza do Site', 70.00, $this->pizzas, noGarcom: false);
        PromocaoRelampago::create([
            'promocao_nome' => 'Só do site',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
        ])->promocaoProdutos()->create(['prp_produto_id' => $foraDoGarcom->id, 'prp_preco_promocional' => 50]);

        $this->assertNotNull($esgotada);
        $this->assertSame([], app(PromocoesVigentesAtendimentoService::class)->listar());
    }

    public function test_aba_aparece_so_quando_habilitada_e_tocar_no_produto_abre_o_lancamento(): void
    {
        $this->relampago();

        Livewire::test(PedidoProdutoSelector::class)
            ->assertDontSee('Promoções');

        Livewire::test(PedidoProdutoSelector::class, ['abaPromocoes' => true])
            ->assertSee('Promoções')
            ->call('abrirPromocoes')
            ->assertSet('verPromocoes', true)
            ->assertSee('Relâmpago Noite')
            ->call('selecionarProduto', $this->frango->id)
            ->assertSet('modalAberta', true)
            ->assertSet('produtoSelecionado.desconto_unit', 10.2)
            ->set('categoriaId', $this->pizzas->id)
            ->assertSet('verPromocoes', false);
    }

    public function test_todos_volta_para_a_grade_de_produtos(): void
    {
        $this->relampago();

        Livewire::test(PedidoProdutoSelector::class, ['abaPromocoes' => true])
            ->call('abrirPromocoes')
            ->set('categoriaId', null)
            ->assertSet('verPromocoes', false);
    }
}
