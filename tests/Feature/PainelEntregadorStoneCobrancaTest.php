<?php

namespace Tests\Feature;

use App\Livewire\PainelEntregador;
use App\Models\Maquininha;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\StonePedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre o novo ponto de disparo "Receber na maquininha" no painel do
 * entregador — envia o Pedido (já EM TRANSPORTE) para a maquininha que o
 * entregador escolheu explicitamente (R7 do plano de integração Stone).
 */
class PainelEntregadorStoneCobrancaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('ALTER TABLE opcoes_entregas AUTO_INCREMENT = 1');

        config([
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
            'services.stone.pedido_direto' => true,
        ]);
        Http::preventStrayRequests();
    }

    private function pedidoEmTransporte(User $entregador, bool $combinadoStone = true): Pedido
    {
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada']);
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Comer no Local']);
        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Entrega']);

        $pedido = Pedido::create([
            'pedido_opcaoentrega_id' => $delivery->id,
            'pedido_status' => 'EM TRANSPORTE',
            'pedido_datahora_transporte' => now(),
            'pedido_usuario_entrega_id' => $entregador->id,
            'pedido_valor_total' => 50.0,
        ]);

        $this->combinarPagamento($pedido, $combinadoStone);

        return $pedido;
    }

    private function combinarPagamento(Pedido $pedido, bool $stoneIntegrada = true): void
    {
        $opcao = OpcoesPagamento::create([
            'opcaopag_nome' => $stoneIntegrada ? 'Cartão Stone' : 'Dinheiro',
            'opcaopag_desc_nfe' => $stoneIntegrada ? 'creditCard' : 'cash',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => $stoneIntegrada,
        ]);

        PagamentosPedido::create([
            'pg_pedido_pedido_id' => $pedido->id,
            'pg_pedido_opcaopagamento_id' => $opcao->id,
            'pg_pedido_opcaopagamento_nome' => $opcao->opcaopag_nome,
            'pg_pedido_valor' => (float) $pedido->pedido_valor_total,
            'pg_pedido_ordem' => 0,
        ]);
    }

    public function test_sem_maquininha_selecionada_mostra_erro(): void
    {
        $entregador = User::factory()->create(['name_first' => 'Entregador']);
        $pedido = $this->pedidoEmTransporte($entregador);
        $this->actingAs($entregador);

        Livewire::test(PainelEntregador::class)
            ->call('abrirModalStone', $pedido->id)
            ->set('stoneMaquininhaId', null)
            ->call('enviarCobrancaStone')
            ->assertHasErrors(['stoneMaquininhaId']);

        $this->assertSame(0, StonePedido::count());
    }

    public function test_envia_cobranca_para_a_maquininha_do_entregador_no_modo_listado(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['id' => 'or_abc', 'code' => 'COD1'], 200)]);

        $entregador = User::factory()->create(['name_first' => 'Entregador']);
        $pedido = $this->pedidoEmTransporte($entregador);
        $maquininhaEntregador = Maquininha::create(['nome' => 'Entregador 1', 'operadora' => 'stone', 'numero_serie' => '999']);
        Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);

        $this->actingAs($entregador);

        Livewire::test(PainelEntregador::class)
            ->call('abrirModalStone', $pedido->id)
            ->set('stoneMaquininhaId', $maquininhaEntregador->id)
            ->call('enviarCobrancaStone')
            ->assertHasNoErrors()
            ->assertSet('stoneStatusModal', 'aguardando');

        $stonePedido = StonePedido::sole();
        $this->assertSame($pedido->id, $stonePedido->stp_pedido_id);
        $this->assertSame($maquininhaEntregador->id, $stonePedido->stp_maquininha_id);
        $this->assertSame('pedido', $stonePedido->stp_origem->value);
        $this->assertSame('listado', $stonePedido->stp_modo->value);
        $this->assertNull($stonePedido->stp_venda_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/orders')
            && $request->data()['poi_payment_settings']['devices_serial_number'] === ['999']
            && ! isset($request->data()['poi_payment_settings']['payment_setup']));
    }

    public function test_botao_receber_some_quando_pagamento_combinado_nao_e_stone(): void
    {
        $entregador = User::factory()->create(['name_first' => 'Entregador']);
        $pedido = $this->pedidoEmTransporte($entregador, combinadoStone: false);
        Maquininha::create(['nome' => 'Entregador 1', 'operadora' => 'stone', 'numero_serie' => '999']);
        $this->actingAs($entregador);

        Livewire::test(PainelEntregador::class)
            ->assertDontSee('Receber na maquininha')
            ->call('abrirModalStone', $pedido->id)
            ->assertSet('modalStoneAberta', false);
    }
}
