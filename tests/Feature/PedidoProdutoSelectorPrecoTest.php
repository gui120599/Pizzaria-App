<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoConsumo;
use App\Models\PromocaoRelampago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PedidoProdutoSelectorPrecoTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Pedido $pedido;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'categoria_nome' => 'Pizzas',
        ]);

        $this->pedido = Pedido::create([
            'pedido_status' => 'INICIADO',
            'pedido_datahora_abertura' => now(),
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

    private function promocao(array $attrs = [], array $produtos = []): PromocaoRelampago
    {
        $promocao = PromocaoRelampago::create(array_merge([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 40,
            'promocao_qtd_vendida' => 0,
        ], $attrs));

        foreach ($produtos as $produto) {
            $promocao->promocaoProdutos()->create([
                'prp_produto_id' => $produto->id,
                'prp_preco_promocional' => 39.90,
            ]);
        }

        return $promocao;
    }

    public function test_grade_do_balcao_ignora_preco_promocional_fora_da_janela(): void
    {
        $this->produto('Portuguesa', 60.00, [
            'produto_preco_promocional' => 45.00,
            'produto_data_inicio_promocao' => now()->subDays(10)->toDateString(),
            'produto_data_final_promocao' => now()->subDay()->toDateString(),
        ]);

        Livewire::test(PedidoProdutoSelector::class)
            ->assertOk()
            ->assertSeeText('60,00')
            ->assertDontSeeText('45,00');
    }

    public function test_grade_do_balcao_aplica_preco_promocional_dentro_da_janela(): void
    {
        $this->produto('Calabresa', 55.00, [
            'produto_preco_promocional' => 39.90,
            'produto_data_inicio_promocao' => now()->subDay()->toDateString(),
            'produto_data_final_promocao' => now()->addDay()->toDateString(),
        ]);

        Livewire::test(PedidoProdutoSelector::class)
            ->assertOk()
            ->assertSeeText('39,90')
            ->assertSeeText('55,00');
    }

    public function test_grade_do_balcao_exibe_preco_de_promocao_relampago(): void
    {
        $marguerita = $this->produto('Marguerita', 55.00);
        $this->promocao(produtos: [$marguerita]);

        Livewire::test(PedidoProdutoSelector::class)
            ->assertOk()
            ->assertSeeText('RELÂMPAGO')
            ->assertSeeText('39,90');
    }

    public function test_confirmar_item_promocional_debita_o_contador_e_congela_o_preco(): void
    {
        $marguerita = $this->produto('Marguerita', 55.00);
        $promocao = $this->promocao(produtos: [$marguerita]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $marguerita->id)
            ->assertSet('produtoSelecionado.promocao_id', $promocao->id)
            // preco_base segue a convenção do sistema: sempre o preço cheio
            // (max venda/promo); o abatimento vive em desconto_unit.
            ->assertSet('produtoSelecionado.preco_base', 55.00)
            ->assertSet('produtoSelecionado.desconto_unit', 15.10)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', null);

        $item = ItensPedido::where('item_pedido_produto_id', $marguerita->id)->first();
        $this->assertNotNull($item);
        $this->assertSame($promocao->id, $item->item_pedido_promocao_id);
        $this->assertSame(55.00, (float) $item->item_pedido_valor_unitario);
        $this->assertSame(15.10, (float) $item->item_pedido_desconto_unitario);
        $this->assertSame(39.90, (float) $item->item_pedido_valor);

        $this->assertSame(1.0, (float) $promocao->fresh()->promocao_qtd_vendida);
        $this->assertSame(1, PromocaoConsumo::where('consumo_item_pedido_id', $item->id)->count());
    }

    public function test_remover_item_promocional_estorna_o_contador(): void
    {
        $marguerita = $this->produto('Marguerita', 55.00);
        $promocao = $this->promocao(produtos: [$marguerita]);

        $component = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $marguerita->id)
            ->call('confirmarItem');

        $item = ItensPedido::where('item_pedido_produto_id', $marguerita->id)->first();

        $component->call('removerItem', (string) $item->id);

        // O item é removido (delete cascateia a linha do ledger via FK), mas o
        // estorno já rodou antes disso: o contador da promoção volta a 0.
        $this->assertSame(0.0, (float) $promocao->fresh()->promocao_qtd_vendida);
        $this->assertNull(ItensPedido::find($item->id));
        $this->assertSame(0, PromocaoConsumo::where('consumo_item_pedido_id', $item->id)->count());
    }

    public function test_ajustar_quantidade_nao_altera_item_promocional(): void
    {
        $marguerita = $this->produto('Marguerita', 55.00);
        $this->promocao(produtos: [$marguerita]);

        $component = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $marguerita->id)
            ->call('confirmarItem');

        $item = ItensPedido::where('item_pedido_produto_id', $marguerita->id)->first();

        $component->call('incrementarQtd', (string) $item->id);

        $this->assertSame(1.0, (float) $item->fresh()->item_pedido_quantidade);
    }

    public function test_limite_por_pedido_bloqueia_confirmacao_e_nao_debita(): void
    {
        $marguerita = $this->produto('Marguerita', 55.00);
        $promocao = $this->promocao(['promocao_limite_por_pedido' => 1], [$marguerita]);

        $component = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $marguerita->id)
            ->call('confirmarItem');

        // Segunda unidade no mesmo pedido excede o limite de 1.
        $component->call('selecionarProduto', $marguerita->id)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', fn ($msg) => ! empty($msg));

        $this->assertSame(1, ItensPedido::where('item_pedido_produto_id', $marguerita->id)->count());
        $this->assertSame(1.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_pizza_inteira_escolhida_via_modal_de_sabores_aplica_relampago(): void
    {
        $this->categoria->update([
            'categoria_permite_sabores' => true,
            'categoria_max_sabores' => 2,
        ]);

        $marguerita = $this->produto('Marguerita', 55.00);
        $promocao = $this->promocao(produtos: [$marguerita]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $marguerita->id)
            ->assertSet('saboresModalAberta', true)
            ->call('confirmarSabores')
            ->assertSet('erroPromocao', null);

        $item = ItensPedido::where('item_pedido_produto_id', $marguerita->id)->first();
        $this->assertNotNull($item);
        $this->assertSame($promocao->id, $item->item_pedido_promocao_id);
        $this->assertSame(55.00, (float) $item->item_pedido_valor_unitario);
        $this->assertSame(39.90, (float) $item->item_pedido_valor);
    }
}
