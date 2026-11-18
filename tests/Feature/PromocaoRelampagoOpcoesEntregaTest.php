<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Exceptions\PromocaoIndisponivelException;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\CardapioService;
use App\Services\Garcom\RetiradaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Promoção relâmpago "só entrega ou retirada": na mesa sai pelo preço
 * normal; nas outras opções (ex.: Comer no Local) o envio é recusado.
 */
class PromocaoRelampagoOpcoesEntregaTest extends TestCase
{
    use RefreshDatabase;

    private Produto $pizza;

    private OpcoesEntregas $retirada;

    private OpcoesEntregas $entregar;

    private OpcoesEntregas $comerNoLocal;

    protected function setUp(): void
    {
        parent::setUp();

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
        ]);

        $this->comerNoLocal = OpcoesEntregas::create(['opcaoentrega_nome' => 'Comer no Local', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada na loja', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
        $this->entregar = OpcoesEntregas::create(['opcaoentrega_nome' => 'Entregar', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => true]);
    }

    /** @param  list<int>|null  $opcoes */
    private function promocao(?array $opcoes): PromocaoRelampago
    {
        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Relâmpago Delivery',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 40,
            'promocao_qtd_vendida' => 0,
            'promocao_opcoes_entrega' => $opcoes,
        ]);
        $promocao->promocaoProdutos()->create(['prp_produto_id' => $this->pizza->id, 'prp_preco_promocional' => 39.90]);

        return $promocao;
    }

    private function soEntregaOuRetirada(): PromocaoRelampago
    {
        return $this->promocao([$this->retirada->id, $this->entregar->id]);
    }

    private function checkout(OpcoesEntregas $opcao): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => $opcao->id,
            'endereco' => 'Rua A, 10',
            'pagamento_nome' => 'Dinheiro',
            'itens' => [['id' => $this->pizza->id, 'qty' => 1]],
        ]);
    }

    private function pedidoDeMesa(): Pedido
    {
        $sessao = SessaoMesa::create([
            'sessao_mesa_mesa_id' => Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA'])->id,
            'sessao_mesa_usuario_id' => User::factory()->admin()->create(['name_first' => 'Ana'])->id,
            'sessao_mesa_status' => 'ABERTA',
        ]);

        return Pedido::create(['pedido_status' => 'INICIADO', 'pedido_sessao_mesa_id' => $sessao->id, 'pedido_datahora_abertura' => now()]);
    }

    public function test_cardapio_aplica_a_promocao_na_entrega_e_recusa_em_opcao_fora_da_lista(): void
    {
        $promocao = $this->soEntregaOuRetirada();

        $this->checkout($this->entregar)->assertOk();
        $this->assertSame($promocao->id, ItensPedido::sole()->item_pedido_promocao_id);
        $this->assertSame('39.90', ItensPedido::sole()->item_pedido_valor);

        $this->checkout($this->comerNoLocal)
            ->assertStatus(422)
            ->assertJsonPath('message', 'A promoção "Relâmpago Delivery" vale só para: Retirada na loja e Entregar. Mude a opção de entrega ou remova o item da promoção.');
        $this->assertSame(1, Pedido::count());
    }

    public function test_pedido_de_mesa_sai_pelo_preco_normal_e_promocao_sem_restricao_continua_valendo(): void
    {
        $this->soEntregaOuRetirada();
        $mesa = $this->pedidoDeMesa();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $mesa->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('confirmarItem');

        $item = ItensPedido::sole();
        $this->assertNull($item->item_pedido_promocao_id);
        $this->assertSame('59.90', $item->item_pedido_valor);

        PromocaoRelampago::query()->update(['promocao_opcoes_entrega' => null]);
        $outraMesa = $this->pedidoDeMesa();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $outraMesa->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('confirmarItem');

        $this->assertSame('39.90', ItensPedido::where('item_pedido_pedido_id', $outraMesa->id)->sole()->item_pedido_valor);
    }

    public function test_retirada_do_garcom_aplica_a_promocao_e_recusa_comer_no_local(): void
    {
        $this->soEntregaOuRetirada();
        $servico = app(RetiradaService::class);
        $rascunho = $servico->rascunho(User::factory()->garcom()->create(['name_first' => 'Bia']));

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $rascunho->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('confirmarItem');
        $this->assertSame('39.90', ItensPedido::sole()->item_pedido_valor);

        try {
            $servico->enviar($rascunho, 'João', '64999991234', $this->comerNoLocal->id);
            $this->fail('Comer no Local não está entre as opções da promoção.');
        } catch (PromocaoIndisponivelException) {
        }

        $this->assertTrue($servico->enviar($rascunho, 'João', '64999991234', $this->retirada->id));
    }

    public function test_cardapio_da_mesa_nao_mostra_a_promocao_restrita_nem_o_preco_dela(): void
    {
        $this->soEntregaOuRetirada();

        $mesa = app(CardapioService::class)->dados(mesa: true);
        $delivery = app(CardapioService::class)->dados();

        $this->assertCount(0, $mesa['promocoesRelampago']);
        $this->assertSame(59.90, (float) $mesa['produtosTela'][$this->pizza->id]->preco);
        $this->assertCount(1, $delivery['promocoesRelampago']);
        $this->assertSame(39.90, (float) $delivery['produtosTela'][$this->pizza->id]->preco);
    }
}
