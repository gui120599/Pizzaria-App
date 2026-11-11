<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoAdicionalRegra;
use App\Services\PromocaoAdicionalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Promoção "Pizza Grande + refri 2L = brotinho grátis": a campanha exige 1
 * unidade da categoria no pedido por oferta aceita, só vale para as opções de
 * entrega marcadas, e a oferta de R$ 0 vira cortesia (fora da NFC-e).
 */
class PromocaoAdicionalRequisitosTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $grandes;

    private Produto $grande;

    private Produto $coca;

    private Produto $guarana;

    private Produto $brotinho;

    private OpcoesEntregas $retirada;

    private OpcoesEntregas $local;

    private PromocaoAdicional $promocao;

    protected function setUp(): void
    {
        parent::setUp();

        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);

        $this->grandes = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio' => true, 'categoria_permite_sabores' => true]);
        $this->grandes->sincronizarQuantidadesSabores(2);
        $bebidas = Categoria::create(['categoria_nome' => 'Refrigerante', 'categoria_cardapio' => true]);
        $brotinhos = Categoria::create(['categoria_nome' => 'Pizza Brotinho', 'categoria_cardapio' => true]);

        $this->grande = $this->produto('Calabresa', $this->grandes, 64.00);
        $this->coca = $this->produto('Coca-Cola 2L', $bebidas, 16.00);
        $this->guarana = $this->produto('Guaraná 2L', $bebidas, 12.00);
        $this->brotinho = $this->produto('Mussarela', $brotinhos, 30.00);

        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada na loja', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $this->local = OpcoesEntregas::create(['opcaoentrega_nome' => 'Comer no Local', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);

        $this->promocao = PromocaoAdicional::create([
            'promoad_nome' => 'Grande + Refri = Brotinho',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
            'promoad_categoria_exigida_id' => $this->grandes->id,
            'promoad_opcoes_entrega' => [(string) $this->retirada->id],
        ]);

        foreach ([$this->coca, $this->guarana] as $refri) {
            $regra = $this->promocao->regras()->create([
                'par_produto_gatilho_id' => $refri->id,
                'par_qtd_maxima_por_pedido' => 1,
            ]);
            $regra->ofertas()->create([
                'pao_produto_oferta_id' => $this->brotinho->id,
                'pao_valor_adicional' => 0,
            ]);
        }
    }

    private function produto(string $nome, Categoria $categoria, float $preco): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    private function checkout(array $itens, ?OpcoesEntregas $entrega = null): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => ($entrega ?? $this->retirada)->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => $itens,
        ]);
    }

    public function test_refri_com_pizza_grande_ganha_brotinho_como_cortesia(): void
    {
        // Refri antes da pizza na lista: a exigência soma o carrinho inteiro.
        $this->checkout([
            ['id' => $this->coca->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
            ['id' => $this->grande->id, 'qty' => 1],
        ])->assertOk();

        $brotinho = ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->firstOrFail();

        $this->assertTrue($brotinho->item_pedido_cortesia);
        $this->assertSame(0.0, (float) $brotinho->item_pedido_valor);
        $this->assertSame(30.0, (float) $brotinho->item_pedido_valor_unitario);
        $this->assertSame(30.0, (float) $brotinho->item_pedido_desconto);
        $this->assertStringContainsString('Grande + Refri = Brotinho', $brotinho->item_pedido_cortesia_motivo);
        $this->assertSame(80.0, (float) Pedido::firstOrFail()->pedido_valor_total);
    }

    public function test_refri_sem_pizza_grande_recusa_a_oferta(): void
    {
        $this->checkout([
            ['id' => $this->coca->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'A promoção "Grande + Refri = Brotinho" exige 1 Pizza Grande no pedido para cada oferta aceita.']);

        $this->assertSame(0, Pedido::count());
    }

    public function test_dois_refris_e_uma_pizza_grande_so_aceitam_uma_oferta(): void
    {
        $this->checkout([
            ['id' => $this->grande->id, 'qty' => 1],
            ['id' => $this->coca->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
            ['id' => $this->guarana->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ])->assertStatus(422);

        $this->checkout([
            ['id' => $this->grande->id, 'qty' => 2],
            ['id' => $this->coca->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
            ['id' => $this->guarana->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ])->assertOk();

        $this->assertSame(2, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
    }

    public function test_pizza_grande_meia_a_meia_conta(): void
    {
        $marguerita = $this->produto('Marguerita', $this->grandes, 64.00);

        $this->checkout([
            ['id' => $this->grande->id, 'qty' => 1, 'sabores' => [['id' => $this->grande->id], ['id' => $marguerita->id]]],
            ['id' => $this->coca->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ])->assertOk();

        $this->assertSame(1, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
    }

    public function test_opcao_de_entrega_fora_da_lista_recusa_a_oferta(): void
    {
        $this->checkout([
            ['id' => $this->grande->id, 'qty' => 1],
            ['id' => $this->coca->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ], $this->local)->assertStatus(422);
    }

    public function test_mesa_nunca_vale_quando_ha_restricao_de_entrega(): void
    {
        $this->assertFalse($this->promocao->entregaPermitida($this->retirada->id, ehMesa: true));
        $this->assertTrue($this->promocao->entregaPermitida($this->retirada->id));
        $this->assertTrue($this->promocao->entregaPermitida(null));
        $this->assertFalse($this->promocao->entregaPermitida($this->local->id));
    }

    public function test_balcao_so_oferece_com_pizza_grande_no_pedido_e_bloqueia_comer_no_local(): void
    {
        $service = app(PromocaoAdicionalService::class);
        $pedido = Pedido::factory()->create(['pedido_opcaoentrega_id' => null, 'pedido_sessao_mesa_id' => null]);

        $this->assertFalse($service->aceitaMaisUmaOferta($this->promocao, $pedido->id));

        ItensPedido::factory()->create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->grande->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $this->assertTrue($service->aceitaMaisUmaOferta($this->promocao, $pedido->id));

        $pedido->update(['pedido_opcaoentrega_id' => $this->local->id]);
        $this->assertFalse($service->aceitaMaisUmaOferta($this->promocao, $pedido->id));
    }

    public function test_save_do_balcao_aponta_campanha_fora_da_entrega(): void
    {
        $this->checkout([
            ['id' => $this->grande->id, 'qty' => 1],
            ['id' => $this->coca->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ])->assertOk();

        $pedido = Pedido::firstOrFail();
        $service = app(PromocaoAdicionalService::class);

        $this->assertSame([], $service->campanhasForaDaEntrega($pedido->id, $this->retirada->id, false));
        $this->assertSame(['Grande + Refri = Brotinho'], $service->campanhasForaDaEntrega($pedido->id, $this->local->id, false));
        $this->assertSame(['Grande + Refri = Brotinho'], $service->campanhasForaDaEntrega($pedido->id, null, false));
    }

    public function test_cardapio_envia_os_gatilhos_para_sugerir_ao_por_a_pizza(): void
    {
        $resposta = $this->get(route('cardapio'))->assertOk();
        $campanhas = $resposta->viewData('campanhasExigencia');

        // A vitrine da campanha mostra a categoria exigida (pizzas), não os refris.
        $vitrine = $resposta->viewData('campanhasAdicionaisAtivas')->first()['produtos']->pluck('produto.id')->all();
        $this->assertContains($this->grande->id, $vitrine);
        $this->assertNotContains($this->coca->id, $vitrine);

        $this->assertCount(1, $campanhas);
        $this->assertSame($this->promocao->id, $campanhas[0]['promocaoId']);
        $this->assertSame('Pizza Grande', $campanhas[0]['categoriaExigida']);
        $this->assertContains($this->grande->id, $campanhas[0]['produtosExigidos']);
        $this->assertEqualsCanonicalizing([$this->coca->id, $this->guarana->id], $campanhas[0]['gatilhos']->pluck('id')->all());
    }

    public function test_oferta_com_valor_continua_sem_cortesia(): void
    {
        PromocaoAdicionalRegra::where('par_produto_gatilho_id', $this->coca->id)->firstOrFail()
            ->ofertas()->update(['pao_valor_adicional' => 5]);

        $this->checkout([
            ['id' => $this->grande->id, 'qty' => 1],
            ['id' => $this->coca->id, 'qty' => 1, 'oferta_produto_id' => $this->brotinho->id],
        ])->assertOk();

        $brotinho = ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->firstOrFail();

        $this->assertFalse($brotinho->item_pedido_cortesia);
        $this->assertSame(5.0, (float) $brotinho->item_pedido_valor);
    }
}
