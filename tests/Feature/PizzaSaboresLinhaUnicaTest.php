<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoConsumo;
use App\Models\PromocaoRelampago;
use App\Models\User;
use App\Models\Venda;
use App\Services\LancamentoItensVendaService;
use App\Services\PromocaoRelampagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pizza de vários sabores é UMA linha de item com os sabores congelados em
 * JSON (modelo RazelFood): checkout, validações do combo, baixa de estoque
 * pelo percentual, venda sem mescla por produto e ledger de promoção.
 */
class PizzaSaboresLinhaUnicaTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Produto $calabresa;

    private Produto $mussarela;

    private Produto $portuguesa;

    private OpcoesEntregas $entrega;

    protected function setUp(): void
    {
        parent::setUp();

        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);

        $this->categoria = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_permite_sabores' => true]);
        $this->categoria->sincronizarQuantidadesSabores(2);

        $this->calabresa = $this->produto('Calabresa', 50.00);
        $this->mussarela = $this->produto('Mussarela', 60.00);
        $this->portuguesa = $this->produto('Portuguesa', 70.00);

        $this->entrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Retirada',
            'opcaoentrega_valor_frete' => 0,
            'opcaoentrega_min_valor_frete' => 0,
        ]);
    }

    private function produto(string $nome, float $preco, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ], $attrs));
    }

    /**
     * @param  array<int, Produto>  $sabores
     */
    private function checkoutPizza(array $sabores, int $qty = 1): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => $this->entrega->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => [[
                'id' => $sabores[0]->id,
                'qty' => $qty,
                'sabores' => array_map(fn (Produto $p) => ['id' => $p->id], $sabores),
            ]],
        ]);
    }

    public function test_checkout_meia_a_meia_grava_uma_linha_com_os_sabores_e_preco_medio(): void
    {
        $this->checkoutPizza([$this->calabresa, $this->mussarela], qty: 2)->assertOk();

        $item = ItensPedido::sole();
        $this->assertSame($this->calabresa->id, $item->item_pedido_produto_id);
        $this->assertSame(2.0, (float) $item->item_pedido_quantidade);
        $this->assertSame(55.0, (float) $item->item_pedido_valor_unitario);
        $this->assertSame(110.0, (float) $item->item_pedido_valor);
        $this->assertSame([$this->calabresa->id, $this->mussarela->id], array_column($item->sabores(), 'produto_id'));
        $this->assertSame([50.0, 50.0], array_column($item->sabores(), 'percentual'));
        $this->assertSame(['MEIA Calabresa', 'MEIA Mussarela'], $item->linhasSabores());
        $this->blade('<x-item-nome :item="$item" />', ['item' => $item])
            ->assertSeeInOrder(['Pizza Grande', 'MEIA Calabresa', 'MEIA Mussarela'])
            ->assertDontSee('½')
            ->assertDontSee(' / ');
        $this->blade('<x-impressao.nome-item :item="$item" />', ['item' => $item])
            ->assertSeeInOrder(['border-black', 'Pizza Grande', '<span class="block">MEIA Calabresa</span>', '<span class="block">MEIA Mussarela</span>'], escape: false);
        $this->assertSame('110.00', Pedido::sole()->pedido_valor_total);
    }

    public function test_descricao_configurada_na_categoria_fica_congelada_no_item(): void
    {
        $opcao = $this->categoria->opcaoQuantidadeSabores(2);
        $opcao->update(['quantidade_sabor_rotulos' => ['½', '']]);
        $this->checkoutPizza([$this->calabresa, $this->mussarela])->assertOk();

        $opcao->update(['quantidade_sabor_rotulos' => ['MEIA', 'MEIA']]);

        $this->assertSame(['½ Calabresa', 'Mussarela'], ItensPedido::sole()->linhasSabores());
    }

    public function test_item_gravado_sem_descricao_usa_o_padrao_por_extenso(): void
    {
        $item = new ItensPedido(['item_pedido_sabores' => [
            ['produto_id' => $this->calabresa->id, 'nome' => 'Calabresa', 'percentual' => 50],
            ['produto_id' => $this->mussarela->id, 'nome' => 'Mussarela', 'percentual' => 50],
        ]]);

        $this->assertSame(['MEIA Calabresa', 'MEIA Mussarela'], $item->linhasSabores());
    }

    public function test_checkout_recusa_quantidade_de_sabores_sem_opcao_na_categoria(): void
    {
        $this->checkoutPizza([$this->calabresa, $this->mussarela, $this->portuguesa])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A categoria "Pizza Grande" não oferece pizza com 3 sabores.');

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_checkout_recusa_sabores_de_categorias_diferentes(): void
    {
        $outra = Categoria::create(['categoria_nome' => 'Pizza Broto', 'categoria_permite_sabores' => true]);
        $outra->sincronizarQuantidadesSabores(2);
        $broto = Produto::create([
            'produto_descricao' => 'Broto Frango',
            'produto_categoria_id' => $outra->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 30.00,
            'produto_cardapio' => true,
        ]);

        $this->checkoutPizza([$this->calabresa, $broto])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Os sabores precisam ser da mesma categoria.');

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_subcategoria_que_herda_usa_as_opcoes_do_pai(): void
    {
        $this->categoria->sincronizarQuantidadesSabores(3);
        $especiais = Categoria::create([
            'categoria_nome' => 'Especiais',
            'categoria_pai_id' => $this->categoria->id,
            'categoria_permite_sabores' => true,
            'categoria_herda_sabores' => true,
        ]);
        $sabores = collect(['Lombo', 'Bacon', 'Atum'])->map(fn (string $nome) => $this->produto($nome, 60.00, ['produto_categoria_id' => $especiais->id]))->all();

        $this->checkoutPizza($sabores)->assertOk();

        $this->assertSame([33.33, 33.33, 33.34], array_column(ItensPedido::sole()->sabores(), 'percentual'));
        $this->assertSame(['TERÇO Lombo', 'TERÇO Bacon', 'TERÇO Atum'], ItensPedido::sole()->linhasSabores());
    }

    public function test_baixa_e_estorno_de_estoque_seguem_o_percentual_de_cada_sabor(): void
    {
        $this->actingAs(User::factory()->create(['name_first' => 'Operador']));
        $this->categoria->quantidadesSabores()->where('quantidade_sabor_quantidade', 2)
            ->update(['quantidade_sabor_percentuais' => json_encode([60, 40])]);
        $this->calabresa->update(['produto_controla_estoque' => true, 'produto_saldo_estoque' => 10]);
        $this->mussarela->update(['produto_controla_estoque' => true, 'produto_saldo_estoque' => 10]);
        $this->checkoutPizza([$this->calabresa, $this->mussarela], qty: 2)->assertOk();
        $pedido = Pedido::sole();

        $pedido->update(['pedido_status' => 'PREPARANDO']);

        $this->assertEqualsWithDelta(8.8, (float) $this->calabresa->refresh()->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(9.2, (float) $this->mussarela->refresh()->produto_saldo_estoque, 0.001);

        $pedido->update(['pedido_status' => 'CANCELADO']);

        $this->assertEqualsWithDelta(10.0, (float) $this->calabresa->refresh()->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $this->mussarela->refresh()->produto_saldo_estoque, 0.001);
    }

    public function test_pizza_de_sabores_nao_mescla_com_inteira_do_mesmo_produto_na_venda(): void
    {
        $this->calabresa->update(['produto_custo_medio' => 20.00]);
        $this->mussarela->update(['produto_custo_medio' => 30.00]);
        $this->checkoutPizza([$this->calabresa, $this->mussarela])->assertOk();
        $pedido = Pedido::sole();
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->calabresa->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 50,
            'item_pedido_valor' => 50,
            'item_pedido_status' => 'INSERIDO',
        ]);
        $venda = Venda::create(['venda_status' => 'INICIADA']);

        app(LancamentoItensVendaService::class)->lancarPedido($venda, $pedido);

        $pizza = ItensVenda::whereNotNull('item_venda_sabores')->sole();
        $inteira = ItensVenda::whereNull('item_venda_sabores')->sole();
        $this->assertSame(1.0, (float) $pizza->item_venda_quantidade);
        $this->assertSame('55.00', $pizza->item_venda_valor);
        $this->assertSame(1.0, (float) $inteira->item_venda_quantidade);
        // Custo da pizza = Σ custo do sabor × percentual: 20 × 50% + 30 × 50%.
        $this->assertEqualsWithDelta(25.0, (float) $pizza->item_venda_custo_unitario, 0.001);
        $this->assertSame($pizza->id, ItensVenda::correspondenteAoItemPedido($venda->id, ItensPedido::whereNotNull('item_pedido_sabores')->sole())?->id);
    }

    public function test_meia_a_meia_promocional_debita_e_estorna_cada_sabor_no_ledger(): void
    {
        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 40,
            'promocao_permite_sabores' => true,
        ]);
        foreach ([$this->calabresa, $this->mussarela] as $produto) {
            $promocao->promocaoProdutos()->create(['prp_produto_id' => $produto->id, 'prp_preco_promocional' => 39.90]);
        }

        $this->checkoutPizza([$this->calabresa, $this->mussarela])->assertOk();

        $item = ItensPedido::sole();
        $this->assertSame('39.90', Pedido::sole()->pedido_valor_total);
        $this->assertSame('1.00', $promocao->fresh()->promocao_qtd_vendida);
        $this->assertSame(['0.50', '0.50'], PromocaoConsumo::where('consumo_item_pedido_id', $item->id)->pluck('consumo_quantidade')->all());

        app(PromocaoRelampagoService::class)->estornarItem($item);

        $this->assertSame('0.00', $promocao->fresh()->promocao_qtd_vendida);
        $this->assertSame(0, PromocaoConsumo::where('consumo_item_pedido_id', $item->id)->whereNull('consumo_revertido_em')->count());
    }
}
