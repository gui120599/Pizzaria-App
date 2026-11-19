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
use App\Models\PromocaoConsumo;
use App\Models\PromocaoRelampago;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pedido novo no Atender Pedido: a prévia já vem com o preço da relâmpago e o
 * save grava o item com a promoção e debita o saldo na mesma transação.
 */
class AtenderPedidoRelampagoTest extends TestCase
{
    use RefreshDatabase;

    private Produto $pizza;

    private OpcoesEntregas $retirada;

    private OpcoesPagamento $dinheiro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->pizza = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 59.90,
        ]);
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada na loja', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $this->dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
    }

    private function promocao(array $attrs = []): PromocaoRelampago
    {
        $promocao = PromocaoRelampago::create($attrs + [
            'promocao_nome' => 'Relâmpago Noite',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 20,
            'promocao_qtd_vendida' => 0,
        ]);
        $promocao->promocaoProdutos()->create(['prp_produto_id' => $this->pizza->id, 'prp_preco_promocional' => 39.90]);

        return $promocao;
    }

    /** Item da prévia montado pelo próprio seletor (sem pedido gravado). */
    private function itemDaPrevia(float $quantidade = 1): array
    {
        $seletor = Livewire::test(PedidoProdutoSelector::class)
            ->call('selecionarProduto', $this->pizza->id);

        for ($i = 1; $i < $quantidade; $i++) {
            $seletor->call('incrementarQuantidadeModal');
        }

        return $seletor->call('confirmarItem')->get('itens')[0];
    }

    private function salvar(array $item, ?OpcoesEntregas $opcao = null): Testable
    {
        return Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [$item])
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => ($opcao ?? $this->retirada)->id,
                'pagamentos' => [['opcaoPagamentoId' => $this->dinheiro->id, 'valor' => number_format($item['valor'], 2, ',', '.'), 'trocoPara' => null]],
            ])
            ->call('save');
    }

    public function test_pedido_novo_grava_o_preco_da_relampago_e_debita_o_saldo(): void
    {
        $promocao = $this->promocao();
        $item = $this->itemDaPrevia();

        $this->assertSame(39.9, $item['valor']);
        $this->salvar($item)->assertHasNoErrors();

        $gravado = ItensPedido::sole();
        $this->assertSame($promocao->id, $gravado->item_pedido_promocao_id);
        $this->assertSame('39.90', $gravado->item_pedido_valor);
        $this->assertSame(1.0, (float) $promocao->fresh()->promocao_qtd_vendida);
        $this->assertSame(1, PromocaoConsumo::count());
    }

    public function test_relampago_que_esgotou_depois_da_previa_nao_grava_o_pedido(): void
    {
        $promocao = $this->promocao();
        $item = $this->itemDaPrevia();
        $promocao->update(['promocao_qtd_vendida' => 20]);

        $this->salvar($item)->assertNotified('Promoção');

        $this->assertSame(0, Pedido::count());
        $this->assertSame(20.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_limite_por_pedido_da_relampago_vale_na_previa(): void
    {
        $this->promocao(['promocao_limite_por_pedido' => 1]);

        $this->salvar($this->itemDaPrevia(quantidade: 2))->assertNotified('Promoção');

        $this->assertSame(0, Pedido::count());
    }

    public function test_relampago_so_entrega_recusa_pedido_novo_comer_no_local(): void
    {
        $comerNoLocal = OpcoesEntregas::create(['opcaoentrega_nome' => 'Comer no Local', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $this->promocao(['promocao_opcoes_entrega' => [$this->retirada->id]]);

        $this->salvar($this->itemDaPrevia(), $comerNoLocal)->assertNotified('Promoção');

        $this->assertSame(0, Pedido::count());
    }
}
