<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;
use App\Models\PromocaoRelampago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Checkout público não pode confiar em nada vindo do navegador sobre a
 * promoção adicional — só a intenção (qual produto foi escolhido); regra,
 * oferta, saldo e valor são sempre resolvidos e revalidados no servidor.
 */
class CardapioCheckoutPromocaoAdicionalTest extends TestCase
{
    use RefreshDatabase;

    private Produto $pizza;

    private Produto $brotinho;

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
            'produto_preco_venda' => 59.90,
            'produto_cardapio' => true,
        ]);

        $this->brotinho = Produto::create([
            'produto_descricao' => 'Pizza Brotinho',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 20.00,
            'produto_cardapio' => true,
        ]);

        $this->entrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Retirada',
            'opcaoentrega_valor_frete' => 0,
            'opcaoentrega_min_valor_frete' => 0,
        ]);
    }

    /** @return array{0: PromocaoAdicionalRegra, 1: PromocaoAdicionalOferta} */
    private function regra(array $attrsRegra = [], array $attrsOferta = []): array
    {
        $promocao = PromocaoAdicional::create([
            'promoad_nome' => 'Dia do Cliente',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
        ]);

        $regra = $promocao->regras()->create(array_merge([
            'par_produto_gatilho_id' => $this->pizza->id,
            'par_preco_gatilho_override' => 39.90,
            'par_qtd_maxima_por_pedido' => 1,
        ], $attrsRegra));

        $oferta = $regra->ofertas()->create(array_merge([
            'pao_produto_oferta_id' => $this->brotinho->id,
            'pao_valor_adicional' => 5.00,
        ], $attrsOferta));

        return [$regra, $oferta];
    }

    private function checkout(array $itens, array $extra = []): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), array_merge([
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => $this->entrega->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => $itens,
        ], $extra));
    }

    public function test_aceitar_oferta_grava_as_duas_linhas_com_preco_do_servidor(): void
    {
        [$regra, $oferta] = $this->regra();

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ])->assertOk();

        $pedido = Pedido::firstOrFail();
        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $itemOferta = ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->firstOrFail();

        $this->assertSame('39.90', $gatilho->item_pedido_valor);
        $this->assertSame($gatilho->id, $itemOferta->item_pedido_origem_id);
        $this->assertSame($oferta->id, $itemOferta->item_pedido_promocao_adicional_oferta_id);
        $this->assertSame('5.00', $itemOferta->item_pedido_valor);
        $this->assertSame('44.90', $pedido->pedido_valor_total);
        $this->assertSame(1.0, (float) $oferta->fresh()->pao_qtd_vendida);
    }

    public function test_recusar_oferta_grava_so_o_gatilho(): void
    {
        $this->regra();

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1],
        ])->assertOk();

        $this->assertSame(1, ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->count());
        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
    }

    public function test_flag_de_oferta_sem_regra_vigente_e_ignorada_silenciosamente(): void
    {
        // Nenhuma regra cadastrada: mandar oferta_produto_id não quebra nem
        // cria nada — o servidor não confia na intenção sem uma regra real.
        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ])->assertOk();

        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
    }

    public function test_produto_ofertado_que_nao_pertence_a_regra_e_ignorado(): void
    {
        $this->regra();
        $outroProduto = Produto::create([
            'produto_descricao' => 'Refrigerante',
            'produto_categoria_id' => $this->pizza->produto_categoria_id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 8.00,
            'produto_cardapio' => true,
        ]);

        // Cliente tenta forjar um produto que não é uma das ofertas cadastradas
        // para esta regra — ignorado, só o gatilho é gravado.
        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'oferta_produto_id' => $outroProduto->id],
        ])->assertOk();

        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $outroProduto->id)->count());
    }

    public function test_valor_e_regra_enviados_pelo_cliente_sao_ignorados(): void
    {
        [$regra, $oferta] = $this->regra();

        // Cliente tenta forjar regra/valor da oferta — campos nem existem na
        // validação, então são descartados; o servidor usa só oferta_produto_id.
        $this->checkout([
            [
                'id' => $this->pizza->id,
                'qty' => 1,
                'oferta_produto_id' => $this->brotinho->id,
                'oferta_regra_id' => 99999,
                'oferta_valor' => 0.01,
            ],
        ])->assertOk();

        $itemOferta = ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->firstOrFail();
        $this->assertSame($oferta->id, $itemOferta->item_pedido_promocao_adicional_oferta_id);
        $this->assertSame('5.00', $itemOferta->item_pedido_valor);
    }

    public function test_sem_override_gatilho_mantem_preco_normal_e_soma_so_a_oferta(): void
    {
        $this->regra(['par_preco_gatilho_override' => null]);

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ])->assertOk();

        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $this->assertSame('59.90', $gatilho->item_pedido_valor);

        $pedido = Pedido::firstOrFail();
        $this->assertSame('64.90', $pedido->pedido_valor_total);
    }

    public function test_override_da_promocao_adicional_vence_relampago_no_checkout(): void
    {
        $this->regra();

        $relampago = PromocaoRelampago::create([
            'promocao_nome' => 'Relâmpago concorrente',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
        ]);
        $relampago->promocaoProdutos()->create([
            'prp_produto_id' => $this->pizza->id,
            'prp_preco_promocional' => 44.90,
        ]);

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1],
        ])->assertOk();

        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $this->assertSame('39.90', $gatilho->item_pedido_valor);
        $this->assertNull($gatilho->item_pedido_promocao_id);
        $this->assertSame(0.0, (float) $relampago->fresh()->promocao_qtd_vendida);
    }

    public function test_limite_por_pedido_bloqueia_checkout_inteiro(): void
    {
        $this->regra(['par_qtd_maxima_por_pedido' => 1]);
        $outraPizza = Produto::create([
            'produto_descricao' => 'Calabresa Extra',
            'produto_categoria_id' => $this->pizza->produto_categoria_id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 59.90,
            'produto_cardapio' => true,
        ]);

        // Mesmo produto-gatilho em 2 linhas de carrinho separadas, ambas
        // pedindo a oferta — a segunda estoura o limite de 1 por pedido.
        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
            ['id' => $this->pizza->id, 'qty' => 1, 'observacao' => 'sem cebola', 'oferta_produto_id' => $this->brotinho->id],
        ])->assertStatus(422);

        $this->assertSame(0, Pedido::count());
    }

    public function test_combo_meia_a_meia_nao_recebe_oferta(): void
    {
        $this->regra();
        $marguerita = Produto::create([
            'produto_descricao' => 'Marguerita',
            'produto_categoria_id' => $this->pizza->produto_categoria_id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
            'produto_cardapio' => true,
        ]);

        $this->checkout([
            [
                'id' => $this->pizza->id,
                'qty' => 1,
                'oferta_produto_id' => $this->brotinho->id,
                'sabores' => [
                    ['id' => $this->pizza->id],
                    ['id' => $marguerita->id],
                ],
            ],
        ])->assertOk();

        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
    }

    // ── N ofertas por gatilho ─────────────────────────────────────────────────

    public function test_cliente_escolhe_entre_n_ofertas_do_mesmo_gatilho(): void
    {
        [$regra, $ofertaBrotinho] = $this->regra();
        $refrigerante = Produto::create([
            'produto_descricao' => 'Refrigerante 2L',
            'produto_categoria_id' => $this->pizza->produto_categoria_id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 12.00,
            'produto_cardapio' => true,
        ]);
        $ofertaRefri = $regra->ofertas()->create([
            'pao_produto_oferta_id' => $refrigerante->id,
            'pao_valor_adicional' => 8.00,
        ]);

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'oferta_produto_id' => $refrigerante->id],
        ])->assertOk();

        $this->assertSame(1, ItensPedido::where('item_pedido_produto_id', $refrigerante->id)->count());
        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
        $this->assertSame(1.0, (float) $ofertaRefri->fresh()->pao_qtd_vendida);
        $this->assertSame(0.0, (float) $ofertaBrotinho->fresh()->pao_qtd_vendida);
    }

    // ── Restrição de forma de pagamento ──────────────────────────────────────

    public function test_pagamento_nao_permitido_remove_override_e_oferta_sem_falhar_o_pedido(): void
    {
        [$regra] = $this->regra();
        $promocao = $regra->promocao;
        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'PIX']);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro']);
        $promocao->opcoesPagamento()->attach($pix->id);

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ], ['opcao_pagamento_id' => $dinheiro->id])->assertOk();

        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $this->assertSame('59.90', $gatilho->item_pedido_valor);
        $this->assertNull($gatilho->item_pedido_promocao_adicional_regra_id);
        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());

        $pedido = Pedido::firstOrFail();
        $this->assertSame('59.90', $pedido->pedido_valor_total);
    }

    public function test_pagamento_permitido_mantem_override_e_oferta(): void
    {
        [$regra] = $this->regra();
        $promocao = $regra->promocao;
        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'PIX']);
        $promocao->opcoesPagamento()->attach($pix->id);

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ], ['opcao_pagamento_id' => $pix->id])->assertOk();

        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $this->assertSame('39.90', $gatilho->item_pedido_valor);
        $this->assertSame(1, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
    }

    public function test_sem_restricao_de_pagamento_qualquer_forma_mantem_a_promocao(): void
    {
        $this->regra();
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro']);

        $this->checkout([
            ['id' => $this->pizza->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ], ['opcao_pagamento_id' => $dinheiro->id])->assertOk();

        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $this->assertSame('39.90', $gatilho->item_pedido_valor);
    }
}
