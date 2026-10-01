<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Pages\PainelPedidos;
use App\Filament\Support\PedidoStatusActions;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Ações trazidas da Central de Pedidos do RazelFood: modal de detalhes (com
 * timeline), link de entrega e troca de entregador.
 */
class PainelPedidosAcoesTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow('2026-09-30 20:00:00');

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function gerente(): User
    {
        $user = User::factory()->create(['name_first' => 'Gerente']);
        $user->assignRole('Gerente');
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:pedido', 'guard_name' => 'web']));

        return $user;
    }

    private function pedido(StatusPedidoEnum $status, array $extras = []): Pedido
    {
        $pedido = Pedido::create($extras + [
            'pedido_status' => $status->value,
            'pedido_datahora_abertura' => Carbon::now(),
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40,
            'item_pedido_valor' => 40,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_ver_detalhes_mostra_cliente_itens_valores_e_timeline(): void
    {
        $this->actingAs($this->gerente());

        $cliente = Cliente::create(['cliente_nome' => 'Fernanda Souza', 'cliente_celular' => '11999990000', 'cliente_tipo' => 'Física']);
        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO, ['pedido_cliente_id' => $cliente->id]);

        Livewire::test(PainelPedidos::class)
            ->mountAction('verDetalhes', ['pedido' => $pedido->id])
            // assertMountedActionModalSee() lê o HTML do MODAL montado
            // (getMountedActionModalHtml()), não o snapshot da página inteira
            // — assertSee() comum não pega isso porque o Filament 4 injeta o
            // conteúdo do modal via um mecanismo de render separado
            // (wire:partial="action-modals" + Alpine), fora do html() padrão
            // do componente.
            ->assertMountedActionModalSee([
                'Fernanda Souza',
                '11999990000',
                'Calabresa',
                'Preparando',
                'Linha do tempo',
                'Valores',
            ]);
    }

    public function test_ver_detalhes_marca_item_de_oferta_de_promocao(): void
    {
        $this->actingAs($this->gerente());

        $pedido = $this->pedido(StatusPedidoEnum::ABERTO);
        $gatilho = $pedido->item_pedido_pedido_id()->first();

        $oferta = Produto::create([
            'produto_descricao' => 'Brotinho',
            'produto_categoria_id' => $this->produto->produto_categoria_id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $oferta->id,
            'item_pedido_origem_id' => $gatilho->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 0,
            'item_pedido_valor' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        Livewire::test(PainelPedidos::class)
            ->mountAction('verDetalhes', ['pedido' => $pedido->id])
            ->assertMountedActionModalSee(['Brotinho', '🎁', 'Linha do tempo']);
    }

    public function test_link_entrega_so_aparece_para_pedido_de_delivery_em_pronto_ou_em_transporte(): void
    {
        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_requer_endereco' => true]);
        $balcao = OpcoesEntregas::create(['opcaoentrega_nome' => 'Balcão', 'opcaoentrega_requer_endereco' => false]);

        $deDelivery = $this->pedido(StatusPedidoEnum::PRONTO, ['pedido_opcaoentrega_id' => $delivery->id]);
        $deBalcao = $this->pedido(StatusPedidoEnum::PRONTO, ['pedido_opcaoentrega_id' => $balcao->id]);
        $aindaAberto = $this->pedido(StatusPedidoEnum::ABERTO, ['pedido_opcaoentrega_id' => $delivery->id]);

        $this->assertTrue(PedidoStatusActions::podeLinkEntrega($deDelivery->refresh()));
        $this->assertFalse(PedidoStatusActions::podeLinkEntrega($deBalcao->refresh()));
        $this->assertFalse(PedidoStatusActions::podeLinkEntrega($aindaAberto->refresh()));
    }

    public function test_link_entrega_mostra_o_qrcode_e_o_link_assinado(): void
    {
        $this->actingAs($this->gerente());

        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_requer_endereco' => true]);
        $pedido = $this->pedido(StatusPedidoEnum::PRONTO, ['pedido_opcaoentrega_id' => $delivery->id]);

        Livewire::test(PainelPedidos::class)
            ->mountAction('linkEntrega', ['pedido' => $pedido->id])
            ->assertMountedActionModalSee('Enviar por WhatsApp')
            ->assertMountedActionModalSee(route('entregador.scan', ['pedido' => $pedido->id]), escape: false);
    }

    public function test_trocar_entregador_reatribui_sem_mudar_o_status(): void
    {
        $this->actingAs($this->gerente());

        $antigo = User::factory()->create(['name_first' => 'Motoboy 1']);
        $antigo->assignRole('Entregador');
        $novo = User::factory()->create(['name_first' => 'Motoboy 2']);
        $novo->assignRole('Entregador');

        $pedido = $this->pedido(StatusPedidoEnum::EM_TRANSPORTE, ['pedido_usuario_entrega_id' => $antigo->id]);

        Livewire::test(PainelPedidos::class)
            ->callAction('trocarEntregador', data: ['entregador' => $novo->id], arguments: ['pedido' => $pedido->id])
            ->assertNotified();

        $pedido->refresh();

        $this->assertSame($novo->id, $pedido->pedido_usuario_entrega_id);
        $this->assertSame(StatusPedidoEnum::EM_TRANSPORTE->value, $pedido->pedido_status);

        // Não é uma transição de status: não deve aparecer na timeline.
        $this->assertFalse(
            $pedido->historicoStatus()->where('hsp_status_para', StatusPedidoEnum::EM_TRANSPORTE->value)->count() > 1
        );
    }

    public function test_pode_trocar_entregador_e_falso_quando_a_operacao_nao_atribui_entregador(): void
    {
        config(['pizzaria.pedidos.atribui_entregador' => false]);

        $pedido = $this->pedido(StatusPedidoEnum::PRONTO);

        $this->assertFalse(PedidoStatusActions::podeTrocarEntregador($pedido));
    }
}
