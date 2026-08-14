<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperarVendaMesasPedidosTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    private Venda $venda;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
            'produto_valor_percentual_icms' => 10,
            'produto_valor_percentual_pis' => 1,
            'produto_valor_percentual_cofins' => 2,
        ]);

        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
        ]);
    }

    private function itemPedido(Pedido $pedido, float $quantidade = 1, ?int $clienteId = null): ItensPedido
    {
        $valor = $this->produto->produto_preco_venda * $quantidade;

        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_cliente_id' => $clienteId,
            'item_pedido_quantidade' => $quantidade,
            'item_pedido_valor_unitario' => $this->produto->produto_preco_venda,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    public function test_lancar_itens_da_mesa_cria_venda_lazy_quando_ainda_nao_existe(): void
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 9', 'mesa_status' => 'LIBERADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $pedido = Pedido::create(['pedido_sessao_mesa_id' => $sessaoMesa->id, 'pedido_status' => 'ENTREGUE']);
        $this->itemPedido($pedido, 1);

        $component = Livewire::test(OperarVenda::class)->assertSet('vendaId', null);
        $component->call('lancarItensDaMesa', $sessaoMesa->id);

        $novaVendaId = $component->get('vendaId');
        $this->assertNotNull($novaVendaId);
        $this->assertNotSame($this->venda->id, $novaVendaId);
        $this->assertSame(1, ItensVenda::where('item_venda_venda_id', $novaVendaId)->count());
    }

    public function test_lancar_pedido_avulso_cria_venda_lazy_quando_ainda_nao_existe(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $this->itemPedido($pedido, 1);

        $component = Livewire::test(OperarVenda::class)->assertSet('vendaId', null);
        $component->call('lancarPedidoAvulso', $pedido->id);

        $novaVendaId = $component->get('vendaId');
        $this->assertNotNull($novaVendaId);
        $this->assertNotSame($this->venda->id, $novaVendaId);
        $this->assertSame(1, ItensVenda::where('item_venda_venda_id', $novaVendaId)->count());
    }

    public function test_lancar_itens_da_mesa_cria_itens_na_venda_e_finaliza_sessao_completa(): void
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $mesa->update(['mesa_status' => 'OCUPADA', 'mesa_sessao_atual_id' => $sessaoMesa->id]);

        $pedido = Pedido::create(['pedido_sessao_mesa_id' => $sessaoMesa->id, 'pedido_status' => 'ENTREGUE']);
        $item = $this->itemPedido($pedido, 2);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('lancarItensDaMesa', $sessaoMesa->id);

        $itemVenda = ItensVenda::where('item_venda_venda_id', $this->venda->id)->firstOrFail();
        $this->assertSame(2.0, (float) $itemVenda->item_venda_quantidade);
        $this->assertSame(100.00, (float) $itemVenda->item_venda_valor);
        $this->assertSame($this->venda->id, $item->fresh()->item_pedido_venda_id);

        $this->assertSame('FINALIZADA', $sessaoMesa->fresh()->sessao_mesa_status);
        $this->assertSame('LIBERADA', $mesa->fresh()->mesa_status);
        $this->assertSame(100.00, (float) $this->venda->fresh()->venda_valor_total);
    }

    public function test_lancar_itens_da_mesa_ignora_pendencia_de_pedido_cancelado(): void
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $mesa->update(['mesa_status' => 'OCUPADA', 'mesa_sessao_atual_id' => $sessaoMesa->id]);

        $pedidoAtivo = Pedido::create(['pedido_sessao_mesa_id' => $sessaoMesa->id, 'pedido_status' => 'ENTREGUE']);
        $this->itemPedido($pedidoAtivo, 1);

        // Pedido cancelado com item ainda INSERIDO (nunca chega a ser lançado,
        // pois é excluído da varredura) não deve impedir a sessão de finalizar.
        $pedidoCancelado = Pedido::create(['pedido_sessao_mesa_id' => $sessaoMesa->id, 'pedido_status' => 'CANCELADO']);
        $this->itemPedido($pedidoCancelado, 1);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('lancarItensDaMesa', $sessaoMesa->id);

        $this->assertSame(1, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
        $this->assertSame('FINALIZADA', $sessaoMesa->fresh()->sessao_mesa_status);
        $this->assertSame('LIBERADA', $mesa->fresh()->mesa_status);
    }

    public function test_lancar_itens_do_cliente_da_mesa_filtra_por_cliente(): void
    {
        $clienteA = Cliente::create(['cliente_nome' => 'Cliente A', 'cliente_tipo' => 'Física']);
        $clienteB = Cliente::create(['cliente_nome' => 'Cliente B', 'cliente_tipo' => 'Física']);

        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $pedido = Pedido::create(['pedido_sessao_mesa_id' => $sessaoMesa->id, 'pedido_status' => 'ENTREGUE']);
        $itemA = $this->itemPedido($pedido, 1, $clienteA->id);
        $itemB = $this->itemPedido($pedido, 1, $clienteB->id);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('lancarItensDoClienteDaMesa', $sessaoMesa->id, $clienteA->id);

        $this->assertNotNull($itemA->fresh()->item_pedido_venda_id);
        $this->assertNull($itemB->fresh()->item_pedido_venda_id);
        $this->assertSame(1, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
    }

    public function test_remover_itens_da_mesa_reverte_lancamento(): void
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $pedido = Pedido::create(['pedido_sessao_mesa_id' => $sessaoMesa->id, 'pedido_status' => 'ENTREGUE']);
        $item = $this->itemPedido($pedido, 2);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->call('lancarItensDaMesa', $sessaoMesa->id);
        $component->call('removerItensDaMesa', $sessaoMesa->id);

        $this->assertSame(0, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
        $this->assertNull($item->fresh()->item_pedido_venda_id);
        $this->assertSame(0.0, (float) $this->venda->fresh()->venda_valor_total);
    }

    public function test_lancar_pedido_avulso_cria_item_na_venda(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $item = $this->itemPedido($pedido, 1);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('lancarPedidoAvulso', $pedido->id);

        $this->assertSame($this->venda->id, $item->fresh()->item_pedido_venda_id);
        $this->assertSame(1, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
    }

    public function test_remover_pedido_avulso_reverte_lancamento(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $item = $this->itemPedido($pedido, 1);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->call('lancarPedidoAvulso', $pedido->id);
        $component->call('removerPedidoAvulso', $pedido->id);

        $this->assertSame(0, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
        $this->assertNull($item->fresh()->item_pedido_venda_id);
    }

    public function test_lancar_pedido_avulso_duas_vezes_nao_soma_a_quantidade_de_novo(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $this->itemPedido($pedido, 1);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->call('lancarPedidoAvulso', $pedido->id);
        $component->call('lancarPedidoAvulso', $pedido->id);

        $itemVenda = ItensVenda::where('item_venda_venda_id', $this->venda->id)->firstOrFail();
        $this->assertSame(1.0, (float) $itemVenda->item_venda_quantidade);
        $this->assertSame(1, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
    }

    public function test_lancar_itens_da_mesa_duas_vezes_nao_soma_a_quantidade_de_novo(): void
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $pedido = Pedido::create(['pedido_sessao_mesa_id' => $sessaoMesa->id, 'pedido_status' => 'ENTREGUE']);
        $this->itemPedido($pedido, 2);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->call('lancarItensDaMesa', $sessaoMesa->id);
        $component->call('lancarItensDaMesa', $sessaoMesa->id);

        $itemVenda = ItensVenda::where('item_venda_venda_id', $this->venda->id)->firstOrFail();
        $this->assertSame(2.0, (float) $itemVenda->item_venda_quantidade);
        $this->assertSame(1, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
    }

    public function test_remover_pedido_avulso_nunca_lancado_nao_afeta_outro_pedido_com_mesmo_produto(): void
    {
        $pedidoA = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $itemA = $this->itemPedido($pedidoA, 2);

        // Mesmo produto de A, mas nunca lançado nesta venda.
        $pedidoB = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $itemB = $this->itemPedido($pedidoB, 3);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->call('lancarPedidoAvulso', $pedidoA->id);
        // B nunca foi lançado — "remover" nele não pode abater a quantidade
        // da linha mesclada que pertence só a A (bug corrigido).
        $component->call('removerPedidoAvulso', $pedidoB->id);

        $itemVenda = ItensVenda::where('item_venda_venda_id', $this->venda->id)->firstOrFail();
        $this->assertSame(2.0, (float) $itemVenda->item_venda_quantidade);
        $this->assertNotNull($itemA->fresh()->item_pedido_venda_id);
        $this->assertNull($itemB->fresh()->item_pedido_venda_id);
    }

    public function test_pedido_lancado_nesta_venda_continua_listado_mas_some_se_vendido_em_outra_venda(): void
    {
        $pedidoNestaVenda = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $this->itemPedido($pedidoNestaVenda, 1);

        $outraVenda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->venda->venda_sessao_caixa_id]);
        $pedidoEmOutraVenda = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $itemOutra = $this->itemPedido($pedidoEmOutraVenda, 1);
        $itemOutra->update(['item_pedido_venda_id' => $outraVenda->id]);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->call('lancarPedidoAvulso', $pedidoNestaVenda->id);

        $ids = $component->instance()->pedidosAvulsos->pluck('id')->all();
        $this->assertContains($pedidoNestaVenda->id, $ids);
        $this->assertNotContains($pedidoEmOutraVenda->id, $ids);
    }

    public function test_busca_filtra_mesas_por_nome_da_mesa_ou_cliente(): void
    {
        $mesaAlvo = Mesa::create(['mesa_nome' => 'Mesa 12', 'mesa_status' => 'LIBERADA']);
        SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesaAlvo->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);

        $mesaOutra = Mesa::create(['mesa_nome' => 'Mesa 3', 'mesa_status' => 'LIBERADA']);
        SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesaOutra->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('buscaMesa', 'Mesa 12');

        $nomes = $component->instance()->mesas->pluck('mesa.mesa_nome')->all();
        $this->assertSame(['Mesa 12'], $nomes);
    }

    public function test_busca_filtra_pedidos_avulsos_por_id_ou_cliente(): void
    {
        $clienteAlvo = Cliente::create(['cliente_nome' => 'Fulano de Tal', 'cliente_tipo' => 'Física']);
        $pedidoAlvo = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_cliente_id' => $clienteAlvo->id]);
        Pedido::create(['pedido_status' => 'ABERTO']);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('buscaPedido', 'Fulano');

        $ids = $component->instance()->pedidosAvulsos->pluck('id')->all();
        $this->assertSame([$pedidoAlvo->id], $ids);
    }
}
