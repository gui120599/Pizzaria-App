<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Maquininha;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\StonePedido;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre a Action + BulkAction "Enviar para a maquininha" / "Cancelar cobrança
 * na maquininha" na listagem administrativa de Pedidos (PedidoResource) —
 * gestão em lote fora das telas de atendimento (AtenderPedido, PainelEntregador,
 * mesa), que já tinham esse disparo individualmente.
 */
class PedidosTableStoneCobrancaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        config([
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
            'services.stone.pedido_direto' => true,
        ]);
        Http::preventStrayRequests();
    }

    private function pedidoComItem(string $status = 'EM TRANSPORTE'): Pedido
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 40.0,
        ]);

        $pedido = Pedido::create(['pedido_status' => $status, 'pedido_valor_total' => 40.0]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40.0,
            'item_pedido_valor' => 40.0,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_action_enviar_stone_cria_pedido_na_maquininha_escolhida(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['id' => 'or_abc', 'code' => 'COD1'], 200)]);

        $pedido = $this->pedidoComItem();
        $maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);

        Livewire::test(ListPedidos::class)
            ->callAction(TestAction::make('enviarStone')->table($pedido), [
                'maquininha_id' => $maquininha->id,
            ])
            ->assertNotified();

        $stonePedido = StonePedido::sole();
        $this->assertSame($pedido->id, $stonePedido->stp_pedido_id);
        $this->assertSame($maquininha->id, $stonePedido->stp_maquininha_id);
        $this->assertSame('pedido', $stonePedido->stp_origem->value);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/orders')
            && $request->data()['poi_payment_settings']['devices_serial_number'] === ['111']);
    }

    public function test_action_enviar_stone_fica_invisivel_para_pedido_finalizado(): void
    {
        $pedido = $this->pedidoComItem('FINALIZADO');
        Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);

        Livewire::test(ListPedidos::class)
            ->assertActionHidden(TestAction::make('enviarStone')->table($pedido));
    }

    public function test_action_enviar_stone_fica_invisivel_com_cobranca_ja_pendente(): void
    {
        $pedido = $this->pedidoComItem();
        $maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);

        StonePedido::create([
            'stp_pedido_id' => $pedido->id,
            'stp_maquininha_id' => $maquininha->id,
            'stp_origem' => 'pedido',
            'stp_order_id' => 'or_existente',
            'stp_valor_solicitado' => 40.0,
            'stp_status' => 'aguardando',
            'stp_modo' => 'listado',
        ]);

        Livewire::test(ListPedidos::class)
            ->assertActionHidden(TestAction::make('enviarStone')->table($pedido));
    }

    public function test_bulk_action_enviar_stone_cria_um_stonepedido_por_pedido_selecionado(): void
    {
        // Cada order da Stone tem id único (stp_order_id é UNIQUE) — não dá
        // pra reusar o mesmo fake fixo quando o teste manda 2 pedidos.
        Http::fake(fn () => Http::response(['id' => 'or_'.uniqid(), 'code' => 'COD_'.uniqid()], 200));

        $pedido1 = $this->pedidoComItem();
        $pedido2 = $this->pedidoComItem();
        $maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);

        Livewire::test(ListPedidos::class)
            ->callTableBulkAction('enviarStoneBulk', [$pedido1, $pedido2], [
                'maquininha_id' => $maquininha->id,
            ])
            ->assertNotified();

        $this->assertSame(2, StonePedido::count());
        $this->assertEqualsCanonicalizing(
            [$pedido1->id, $pedido2->id],
            StonePedido::pluck('stp_pedido_id')->all(),
        );
    }

    public function test_bulk_action_enviar_stone_ignora_pedidos_ja_com_cobranca_pendente(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['id' => 'or_abc', 'code' => 'COD1'], 200)]);

        $pedido1 = $this->pedidoComItem();
        $pedido2 = $this->pedidoComItem();
        $maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);

        StonePedido::create([
            'stp_pedido_id' => $pedido1->id,
            'stp_maquininha_id' => $maquininha->id,
            'stp_origem' => 'pedido',
            'stp_order_id' => 'or_existente',
            'stp_valor_solicitado' => 40.0,
            'stp_status' => 'aguardando',
            'stp_modo' => 'listado',
        ]);

        Livewire::test(ListPedidos::class)
            ->callTableBulkAction('enviarStoneBulk', [$pedido1, $pedido2], [
                'maquininha_id' => $maquininha->id,
            ]);

        // pedido1 já tinha StonePedido pendente — não deve ganhar um segundo.
        $this->assertSame(2, StonePedido::count());
        $this->assertSame($pedido2->id, StonePedido::where('stp_order_id', 'or_abc')->value('stp_pedido_id'));
    }

    public function test_action_cancelar_stone_so_aparece_com_cobranca_pendente(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);

        $pedido = $this->pedidoComItem();

        Livewire::test(ListPedidos::class)
            ->assertActionHidden(TestAction::make('cancelarStone')->table($pedido));

        $maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);
        $stonePedido = StonePedido::create([
            'stp_pedido_id' => $pedido->id,
            'stp_maquininha_id' => $maquininha->id,
            'stp_origem' => 'pedido',
            'stp_order_id' => 'or_existente',
            'stp_valor_solicitado' => 40.0,
            'stp_status' => 'aguardando',
            'stp_modo' => 'listado',
        ]);

        Livewire::test(ListPedidos::class)
            ->assertActionVisible(TestAction::make('cancelarStone')->table($pedido))
            ->callAction(TestAction::make('cancelarStone')->table($pedido))
            ->assertNotified();

        $this->assertSame('cancelado', $stonePedido->fresh()->stp_status->value);
    }

    public function test_bulk_action_cancelar_stone_cancela_so_os_pendentes(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);

        $pedido1 = $this->pedidoComItem();
        $pedido2 = $this->pedidoComItem();
        $maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);

        $stonePedido1 = StonePedido::create([
            'stp_pedido_id' => $pedido1->id,
            'stp_maquininha_id' => $maquininha->id,
            'stp_origem' => 'pedido',
            'stp_order_id' => 'or_1',
            'stp_valor_solicitado' => 40.0,
            'stp_status' => 'aguardando',
            'stp_modo' => 'listado',
        ]);

        Livewire::test(ListPedidos::class)
            ->callTableBulkAction('cancelarStoneBulk', [$pedido1, $pedido2])
            ->assertNotified();

        $this->assertSame('cancelado', $stonePedido1->fresh()->stp_status->value);
        $this->assertSame(0, StonePedido::where('stp_pedido_id', $pedido2->id)->count());
    }
}
