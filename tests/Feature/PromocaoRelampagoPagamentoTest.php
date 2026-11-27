<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoConsumo;
use App\Models\PromocaoRelampago;
use App\Models\User;
use App\Services\CardapioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Promoção relâmpago "só Pix ou Dinheiro": com outra forma de pagamento o
 * item sai pelo preço normal — no cardápio direto (o checkout reprecifica),
 * no Atender Pedido depois de o atendente confirmar.
 */
class PromocaoRelampagoPagamentoTest extends TestCase
{
    use RefreshDatabase;

    private Produto $pizza;

    private OpcoesEntregas $retirada;

    private OpcoesPagamento $pix;

    private OpcoesPagamento $cartao;

    private PromocaoRelampago $promocao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);

        $this->pizza = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 59.90,
            'produto_cardapio' => true,
            'produto_cardapio_garcom' => true,
        ]);
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
        $this->pix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_desc_nfe' => 'InstantPayment', 'opcaopag_aparece_cardapio' => true]);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash', 'opcaopag_aparece_cardapio' => true]);
        $this->cartao = OpcoesPagamento::create(['opcaopag_nome' => 'Cartão de Crédito', 'opcaopag_desc_nfe' => 'creditCard', 'opcaopag_aparece_cardapio' => true]);

        $this->promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Relâmpago Pix',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 10,
            'promocao_qtd_vendida' => 0,
        ]);
        $this->promocao->promocaoProdutos()->create(['prp_produto_id' => $this->pizza->id, 'prp_preco_promocional' => 39.90]);
        $this->promocao->opcoesPagamento()->attach([$this->pix->id, $dinheiro->id]);
    }

    private function checkout(OpcoesPagamento $pagamento): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => $this->retirada->id,
            'opcao_pagamento_id' => $pagamento->id,
            'pagamento_nome' => $pagamento->opcaopag_nome,
            'itens' => [['id' => $this->pizza->id, 'qty' => 1]],
        ]);
    }

    private function salvar(array $itens, OpcoesPagamento $pagamento, ?Pedido $pedido = null): Testable
    {
        return Livewire::test(AtenderPedido::class, $pedido ? ['pedido' => $pedido] : [])
            ->set('itensCarrinho', $itens)
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => $this->retirada->id,
                'pagamentos' => [['opcaoPagamentoId' => $pagamento->id, 'valor' => number_format(collect($itens)->sum('valor'), 2, ',', '.'), 'trocoPara' => null]],
            ])
            ->call('save');
    }

    private function itemDaPrevia(): array
    {
        return Livewire::test(PedidoProdutoSelector::class)
            ->call('selecionarProduto', $this->pizza->id)
            ->call('confirmarItem')
            ->get('itens')[0];
    }

    public function test_cardapio_cobra_o_preco_normal_com_cartao_e_o_promocional_com_pix(): void
    {
        $this->checkout($this->cartao)->assertOk();
        $item = ItensPedido::sole();
        $this->assertSame('59.90', $item->item_pedido_valor);
        $this->assertNull($item->item_pedido_promocao_id);
        $this->assertSame(0, PromocaoConsumo::count());

        $this->checkout($this->pix)->assertOk();
        $item = ItensPedido::latest('id')->first();
        $this->assertSame('39.90', $item->item_pedido_valor);
        $this->assertSame($this->promocao->id, $item->item_pedido_promocao_id);
        $this->assertSame(1.0, (float) $this->promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_previa_da_sacola_segue_a_forma_de_pagamento(): void
    {
        $previa = fn (OpcoesPagamento $pagamento) => $this->postJson(route('cardapio.carrinho.precificar'), [
            'opcao_pagamento_id' => $pagamento->id,
            'itens' => [['id' => $this->pizza->id, 'qty' => 1]],
        ])->assertOk();

        $previa($this->cartao);
        $previa($this->pix);
        $this->assertNull($this->promocao->fresh()->promocao_ultimo_reset_em);
        $this->assertSame(0, PromocaoConsumo::count());
    }

    public function test_card_do_cardapio_mostra_as_formas_aceitas(): void
    {
        $dados = app(CardapioService::class)->dados();

        $this->assertSame('Pix ou Dinheiro', $dados['promocoesRelampago']->first()['pagamentos']);
        $this->assertSame([$this->pix->id, $this->pix->id + 1], $dados['produtosTela'][$this->pizza->id]->relampagoPagamentos);
        $this->assertStringContainsString('Válido para pagamento em Pix ou Dinheiro.', $dados['anunciosPromocoes'][0]['nota']);
    }

    public function test_pedido_novo_com_cartao_pede_confirmacao_e_nao_grava(): void
    {
        $this->salvar([$this->itemDaPrevia()], $this->cartao)
            ->assertActionMounted('confirmarPrecoNormalRelampago');

        $this->assertSame(0, Pedido::count());
    }

    public function test_pedido_novo_confirmado_grava_pelo_preco_normal_sem_consumir(): void
    {
        $this->salvar([$this->itemDaPrevia()], $this->cartao)
            ->callMountedAction()
            ->assertHasNoErrors();

        $item = ItensPedido::sole();
        $this->assertSame('59.90', $item->item_pedido_valor);
        $this->assertNull($item->item_pedido_promocao_id);
        $this->assertSame(0.0, (float) $this->promocao->fresh()->promocao_qtd_vendida);
        $this->assertSame(59.9, (float) Pedido::sole()->pedido_valor_total);
        // A forma de pagamento acompanhou o novo total (senão a soma recusaria o save).
        $this->assertSame(59.9, (float) PagamentosPedido::sole()->pg_pedido_valor);
    }

    public function test_pedido_novo_com_pix_grava_a_promocao_sem_perguntar(): void
    {
        $this->salvar([$this->itemDaPrevia()], $this->pix)
            ->assertActionNotMounted('confirmarPrecoNormalRelampago');

        $this->assertSame('39.90', ItensPedido::sole()->item_pedido_valor);
        $this->assertSame(1.0, (float) $this->promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_edicao_confirmada_devolve_o_saldo_e_regrava_o_preco(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_datahora_abertura' => now()]);
        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('confirmarItem');
        $this->assertSame(1.0, (float) $this->promocao->fresh()->promocao_qtd_vendida);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => $this->retirada->id,
                'pagamentos' => [['opcaoPagamentoId' => $this->cartao->id, 'valor' => '39,90', 'trocoPara' => null]],
            ])
            ->call('save')
            ->assertActionMounted('confirmarPrecoNormalRelampago')
            ->callMountedAction();

        $item = ItensPedido::sole();
        $this->assertSame('59.90', $item->item_pedido_valor);
        $this->assertNull($item->item_pedido_promocao_id);
        $this->assertSame(0.0, (float) $this->promocao->fresh()->promocao_qtd_vendida);
        $this->assertNotNull(PromocaoConsumo::sole()->consumo_revertido_em);
    }
}
