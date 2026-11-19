<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoCombo;
use App\Models\User;
use App\Services\Garcom\RetiradaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Promoção Combo no atendimento (garçom e Atender Pedido): valores da lista
 * com o combo, convite para completar, comemoração e gravação no envio.
 */
class PedidoProdutoSelectorComboTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $grande;

    private Produto $calabresa;

    private Produto $frango;

    private OpcoesEntregas $retirada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->grande = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio_garcom' => true]);
        $this->calabresa = $this->produto('Calabresa', 59.90);
        $this->frango = $this->produto('Frango', 50.10);
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
    }

    private function produto(string $nome, float $preco, ?Categoria $categoria = null): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => ($categoria ?? $this->grande)->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio_garcom' => true,
        ]);
    }

    private function lancar(Testable $seletor, Produto $produto): Testable
    {
        return $seletor->call('selecionarProduto', $produto->id)->call('confirmarItem');
    }

    public function test_garcom_ve_o_convite_na_primeira_pizza_e_a_comemoracao_na_segunda(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();
        $rascunho = app(RetiradaService::class)->rascunho(User::factory()->garcom()->create(['name_first' => 'Bia']));
        $seletor = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $rascunho->id]);

        $this->lancar($seletor, $this->calabresa)
            ->assertSet('comboModal.tipo', 'convite')
            ->assertSet('comboModal.faltam', 1);
        $this->assertSame([$this->calabresa->id, $this->frango->id], collect($seletor->get('comboModal.produtos'))->pluck('id')->all());

        $seletor->call('escolherDoCombo', $this->frango->id)
            ->assertSet('comboModal', null)
            ->assertSet('produtoSelecionadoId', $this->frango->id)
            ->call('confirmarItem')
            ->assertSet('comboModal.tipo', 'conquista')
            ->assertSet('comboModal.desconto', 22.01)
            ->assertSet('comboModal.valor_cheio', 110.0);

        $this->assertSame([47.91, 40.08], collect($seletor->get('itens'))->pluck('valor')->all());
        $this->assertSame([11.99, 10.02], collect($seletor->get('itens'))->pluck('desconto_combo')->all());
    }

    public function test_convite_recusado_so_volta_depois_de_fechar_outro_combo(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();
        $rascunho = app(RetiradaService::class)->rascunho(User::factory()->garcom()->create(['name_first' => 'Bia']));
        $seletor = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $rascunho->id]);

        $this->lancar($seletor, $this->calabresa)->call('recusarCombo');
        $this->lancar($seletor, $this->produto('Refri', 12.00, Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio_garcom' => true])))
            ->assertSet('comboModal', null);

        $this->lancar($seletor, $this->frango)->assertSet('comboModal.tipo', 'conquista')->call('recusarCombo');
        $this->lancar($seletor, $this->calabresa)->assertSet('comboModal.tipo', 'convite');
    }

    public function test_atender_pedido_novo_mostra_e_grava_o_combo(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();
        $seletor = Livewire::test(PedidoProdutoSelector::class);
        $this->lancar($seletor, $this->calabresa);
        $this->lancar($seletor, $this->frango)->assertSet('comboModal.tipo', 'conquista');

        $itens = $seletor->get('itens');
        $this->assertSame(87.99, round(collect($itens)->sum('valor'), 2));

        Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', $itens)
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => $this->retirada->id,
                'pagamentos' => [['opcaoPagamentoId' => OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash'])->id, 'valor' => '87,99', 'trocoPara' => null]],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $pedido = Pedido::sole();
        $this->assertSame('87.99', $pedido->pedido_valor_total);
        $this->assertSame('110.00', $pedido->pedido_valor_itens);
        $this->assertSame(['59.90', '50.10'], ItensPedido::orderBy('id')->pluck('item_pedido_valor_unitario')->all());
        $this->assertSame(['11.99', '10.02'], ItensPedido::orderBy('id')->pluck('item_pedido_desconto_combo')->all());
        $this->assertSame(['0.00', '0.00'], ItensPedido::orderBy('id')->pluck('item_pedido_desconto')->all());
    }

    public function test_retirada_enviada_tira_o_combo_restrito_a_delivery(): void
    {
        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => true]);
        PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_opcoes_entrega' => [$delivery->id]]);
        $servico = app(RetiradaService::class);
        $rascunho = $servico->rascunho(User::factory()->garcom()->create(['name_first' => 'Bia']));
        $seletor = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $rascunho->id]);
        $this->lancar($seletor, $this->calabresa);
        $this->lancar($seletor, $this->frango);

        $this->assertSame('11.99', ItensPedido::where('item_pedido_produto_id', $this->calabresa->id)->value('item_pedido_desconto_combo'));

        $this->assertTrue($servico->enviar($rascunho, 'João', '64999991234', $this->retirada->id));

        $this->assertSame('110.00', $rascunho->fresh()->pedido_valor_total);
        $this->assertSame(['0.00', '0.00'], ItensPedido::orderBy('id')->pluck('item_pedido_desconto_combo')->all());
    }
}
