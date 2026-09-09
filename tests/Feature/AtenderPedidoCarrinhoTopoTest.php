<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Section de Carrinho no topo da AtenderPedido (acima do Cliente, espelhando
 * o AttendOrder do razelfood) — cobre o repasse de eventos pro
 * PedidoProdutoSelector, que continua sendo o dono de verdade do estado dos
 * itens. Cada lado do relay é testado separadamente porque, na prática (fora
 * do teste), são dois componentes Livewire distintos conversando via evento
 * no navegador — não uma única árvore de componentes dentro do mesmo request.
 */
class AtenderPedidoCarrinhoTopoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function pedidoComItem(): array
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 40.0,
        ]);
        $pedido = Pedido::create(['pedido_status' => 'ABERTO']);

        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40.0,
            'item_pedido_valor' => 40.0,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return [$pedido, $produto, $item];
    }

    public function test_carrinho_no_topo_mostra_os_itens_do_pedido(): void
    {
        [$pedido, $produto] = $this->pedidoComItem();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSee('Carrinho')
            ->assertSee($produto->produto_descricao)
            ->assertSee('Cliente');
    }

    public function test_remover_item_repassa_evento_para_o_seletor_de_produtos(): void
    {
        [$pedido, , $item] = $this->pedidoComItem();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('removerItem', (string) $item->id)
            ->assertDispatchedTo(PedidoProdutoSelector::class, 'pedido-remover-item', itemId: (string) $item->id);
    }

    public function test_incrementar_e_decrementar_repassam_evento_para_o_seletor_de_produtos(): void
    {
        [$pedido, , $item] = $this->pedidoComItem();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('incrementarQtd', (string) $item->id)
            ->assertDispatchedTo(PedidoProdutoSelector::class, 'pedido-incrementar-item', itemId: (string) $item->id)
            ->call('decrementarQtd', (string) $item->id)
            ->assertDispatchedTo(PedidoProdutoSelector::class, 'pedido-decrementar-item', itemId: (string) $item->id);
    }

    public function test_editar_repassa_evento_para_o_seletor_de_produtos(): void
    {
        [$pedido, , $item] = $this->pedidoComItem();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirEditModal', (string) $item->id)
            ->assertDispatchedTo(PedidoProdutoSelector::class, 'pedido-abrir-edicao-item', itemId: (string) $item->id);
    }

    public function test_seletor_remove_item_ao_receber_o_evento_repassado(): void
    {
        [$pedido, , $item] = $this->pedidoComItem();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->assertCount('itens', 1)
            ->dispatch('pedido-remover-item', itemId: (string) $item->id)
            ->assertCount('itens', 0);

        $this->assertDatabaseMissing('itens_pedidos', ['id' => $item->id]);
    }

    public function test_seletor_incrementa_quantidade_ao_receber_o_evento_repassado(): void
    {
        [$pedido, , $item] = $this->pedidoComItem();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->dispatch('pedido-incrementar-item', itemId: (string) $item->id)
            ->assertSet('itens.0.quantidade', 2.0);

        $this->assertSame(2.0, (float) $item->fresh()->item_pedido_quantidade);
    }

    public function test_seletor_abre_modal_de_edicao_ao_receber_o_evento_repassado(): void
    {
        [$pedido, , $item] = $this->pedidoComItem();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->dispatch('pedido-abrir-edicao-item', itemId: (string) $item->id)
            ->assertSet('editModalAberta', true)
            ->assertSet('editItemId', (string) $item->id);
    }
}
