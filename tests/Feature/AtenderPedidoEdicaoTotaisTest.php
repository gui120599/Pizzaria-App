<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Livewire\PedidoProdutoSelector;
use App\Models\AdicionaisProduto;
use App\Models\Adicional;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Services\TotaisPedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Incidente do pedido 58656: itens incluídos na edição sem o total acompanhar. */
class AtenderPedidoEdicaoTotaisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function produto(string $nome, float $preco): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Diversos'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    private function pedidoDoCardapio(): Pedido
    {
        $pizza = $this->produto('Calabresa Especial', 69.90);
        $pedido = Pedido::create([
            'pedido_status' => 'PREPARANDO',
            'pedido_origem' => 'cardapio',
            'pedido_valor_itens' => 139.80,
            'pedido_valor_total' => 139.80,
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $pizza->id,
            'item_pedido_quantidade' => 2,
            'item_pedido_valor_unitario' => 69.90,
            'item_pedido_valor' => 139.80,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    private function incluir(Pedido $pedido, Produto $produto): void
    {
        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $produto->id)
            ->call('confirmarItem');
    }

    public function test_incluir_itens_atualiza_o_total_sem_chamar_save(): void
    {
        $pedido = $this->pedidoDoCardapio();

        $this->incluir($pedido, $this->produto('La Fruit Maracujá', 10.00));
        $this->incluir($pedido, $this->produto('Coca-Cola 2L', 16.00));

        $pedido->refresh();
        $this->assertSame(165.80, (float) $pedido->pedido_valor_total);
        $this->assertSame(165.80, (float) $pedido->pedido_valor_itens);
        $this->assertSame(3, $pedido->item_pedido_pedido_id()->count());
    }

    public function test_save_que_falha_por_falta_de_pagamento_mantem_o_total_correto(): void
    {
        $pedido = $this->pedidoDoCardapio();
        $this->incluir($pedido, $this->produto('Coca-Cola 2L', 16.00));

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])->call('save');

        $this->assertSame(155.80, (float) $pedido->refresh()->pedido_valor_total);
    }

    public function test_aviso_de_divergencia_quando_pagamento_nao_bate_com_o_total(): void
    {
        $pedido = $this->pedidoDoCardapio();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSet('entregaPagamentoData.pagamentos', fn ($p) => true)
            ->assertSee('não bate com o total');
    }

    public function test_quantidade_incrementa_e_decrementa(): void
    {
        $pedido = $this->pedidoDoCardapio();
        $item = ItensPedido::sole();

        $componente = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('incrementarQtd', (string) $item->id);
        $this->assertSame(209.70, (float) $pedido->refresh()->pedido_valor_total);

        $componente->call('decrementarQtd', (string) $item->id);
        $this->assertSame(139.80, (float) $pedido->refresh()->pedido_valor_total);
    }

    public function test_remover_item_atualiza_o_total(): void
    {
        $pedido = $this->pedidoDoCardapio();
        $this->incluir($pedido, $this->produto('Coca-Cola 2L', 16.00));
        $coca = ItensPedido::orderByDesc('id')->first();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('removerItem', (string) $coca->id);

        $this->assertSame(139.80, (float) $pedido->refresh()->pedido_valor_total);
        $this->assertSame(1, ItensPedido::count());
    }

    public function test_trocar_adicionais_atualiza_o_total(): void
    {
        $pedido = $this->pedidoDoCardapio();
        $pizza = Produto::first();
        $bacon = Adicional::create(['adicional_nome' => 'Bacon', 'adicional_valor' => 5.00]);
        AdicionaisProduto::create(['ap_adicional_id' => $bacon->id, 'ap_produto_id' => $pizza->id]);
        $item = ItensPedido::sole();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('abrirEditModal', (string) $item->id)
            ->call('toggleEditAdicional', $bacon->id)
            ->call('salvarEdicaoItem');

        $this->assertSame(149.80, (float) $pedido->refresh()->pedido_valor_total);
    }

    public function test_item_com_desconto_nao_conta_o_desconto_em_dobro(): void
    {
        $pedido = $this->pedidoDoCardapio();
        $produto = Produto::first();

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 20,
            'item_pedido_valor' => 15,
            'item_pedido_desconto' => 5,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
        $this->incluir($pedido, $this->produto('Suco', 10.00));

        $pedido->refresh();
        $this->assertSame(164.80, (float) $pedido->pedido_valor_total);
        $this->assertSame(5.0, (float) $pedido->pedido_valor_desconto);
        $this->assertSame(169.80, (float) $pedido->pedido_valor_itens);
    }

    public function test_falha_no_recalculo_desfaz_a_inclusao_do_item(): void
    {
        $pedido = $this->pedidoDoCardapio();
        $this->mock(TotaisPedidoService::class, function ($mock) {
            $mock->shouldReceive('travar')->andReturnUsing(fn (int $id) => Pedido::findOrFail($id));
            $mock->shouldReceive('recalcular')->andThrow(new RuntimeException('falha'));
        });

        try {
            $this->incluir($pedido, $this->produto('Coca-Cola 2L', 16.00));
        } catch (\Throwable) {
        }

        $this->assertSame(1, ItensPedido::count());
        $this->assertSame(139.80, (float) $pedido->refresh()->pedido_valor_total);
    }
}
