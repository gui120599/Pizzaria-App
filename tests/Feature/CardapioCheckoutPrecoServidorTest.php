<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O checkout público não pode confiar no preço enviado pelo navegador.
 */
class CardapioCheckoutPrecoServidorTest extends TestCase
{
    use RefreshDatabase;

    private Produto $pizza;

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

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        $this->pizza = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
            'produto_cardapio' => true,
        ]);

        $this->entrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Retirada',
            'opcaoentrega_valor_frete' => 0,
            'opcaoentrega_min_valor_frete' => 0,
        ]);
    }

    private function checkout(array $itens): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => $this->entrega->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => $itens,
        ]);
    }

    public function test_preco_enviado_pelo_cliente_e_ignorado(): void
    {
        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'preco' => 0.01, 'preco_original' => 0.01],
        ])->assertOk();

        $pedido = Pedido::firstOrFail();

        $this->assertSame('55.00', $pedido->pedido_valor_total);
        $this->assertSame('55.00', ItensPedido::where('item_pedido_pedido_id', $pedido->id)->first()->item_pedido_valor);
    }

    public function test_promocao_relampago_e_aplicada_e_debita_o_contador(): void
    {
        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 40,
        ]);
        $promocao->promocaoProdutos()->create([
            'prp_produto_id' => $this->pizza->id,
            'prp_preco_promocional' => 39.90,
        ]);

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 2, 'preco' => 55.00],
        ])->assertOk();

        $pedido = Pedido::firstOrFail();

        $this->assertSame('79.80', $pedido->pedido_valor_total);
        $this->assertSame('2.00', $promocao->fresh()->promocao_qtd_vendida);
        $this->assertSame($promocao->id, ItensPedido::where('item_pedido_pedido_id', $pedido->id)->first()->item_pedido_promocao_id);
    }

    public function test_promocao_so_inteira_cobra_fracao_pelo_preco_normal_sem_debitar(): void
    {
        // Segundo sabor para montar a meia a meia.
        $marguerita = Produto::create([
            'produto_descricao' => 'Marguerita',
            'produto_categoria_id' => $this->pizza->produto_categoria_id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
            'produto_cardapio' => true,
        ]);

        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 40,
            'promocao_permite_sabores' => false,
        ]);
        $promocao->promocaoProdutos()->create([
            'prp_produto_id' => $this->pizza->id,
            'prp_preco_promocional' => 39.90,
        ]);

        // Inteira → promocional (39,90) e debita o contador.
        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'preco' => 39.90],
        ])->assertOk();

        // Meia a meia com o produto da promoção "só inteira" → volta ao normal:
        // (55 + 55) / 2 = 55,00, sem promoção e sem debitar o contador.
        $this->checkout([
            [
                'id' => $this->pizza->id,
                'qty' => 1,
                'sabores' => [
                    ['id' => $this->pizza->id],
                    ['id' => $marguerita->id],
                ],
            ],
        ])->assertOk();

        $meia = Pedido::latest('id')->first();

        $this->assertSame('55.00', $meia->pedido_valor_total);
        $this->assertNull(ItensPedido::where('item_pedido_pedido_id', $meia->id)->first()->item_pedido_promocao_id);
        // Só a pizza inteira debitou: a fração não consome a promoção.
        $this->assertSame('1.00', $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_checkout_falha_quando_a_promocao_esgota(): void
    {
        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 1,
        ]);
        $promocao->promocaoProdutos()->create([
            'prp_produto_id' => $this->pizza->id,
            'prp_preco_promocional' => 39.90,
        ]);

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 3, 'preco' => 39.90],
        ])->assertStatus(422);

        // Nada gravado: pedido e itens caem junto com o débito.
        $this->assertSame(0, Pedido::count());
        $this->assertSame('0.00', $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_limite_por_pedido_e_respeitado(): void
    {
        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 40,
            'promocao_limite_por_pedido' => 2,
        ]);
        $promocao->promocaoProdutos()->create([
            'prp_produto_id' => $this->pizza->id,
            'prp_preco_promocional' => 39.90,
        ]);

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 3, 'preco' => 39.90],
        ])->assertStatus(422);

        $this->assertSame(0, Pedido::count());
    }
}
