<?php

namespace Tests\Feature;

use App\Livewire\MesaStoneCobranca;
use App\Models\Maquininha;
use App\Models\Mesa;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre o novo ponto de disparo "Cobrar conta na maquininha" embutido na
 * tela legada de pedidos da mesa — envia a conta inteira da sessão pra
 * maquininha explicitamente escolhida pelo garçom (R7 do plano de
 * integração Stone).
 */
class MesaStoneCobrancaTest extends TestCase
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

        OpcoesPagamento::create([
            'opcaopag_nome' => 'Cartão Stone',
            'opcaopag_desc_nfe' => 'creditCard',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => true,
        ]);
    }

    private function sessaoMesaComConta(): SessaoMesa
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 7', 'mesa_status' => 'OCUPADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        Pedido::create([
            'pedido_sessao_mesa_id' => $sessaoMesa->id,
            'pedido_status' => 'ENTREGUE',
            'pedido_valor_total' => 80.0,
        ]);

        return $sessaoMesa;
    }

    public function test_sem_maquininha_selecionada_mostra_erro(): void
    {
        $sessaoMesa = $this->sessaoMesaComConta();

        Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $sessaoMesa->id])
            ->call('abrirModal')
            ->set('maquininhaId', null)
            ->call('enviarCobranca')
            ->assertHasErrors(['maquininhaId']);

        $this->assertSame(0, StonePedido::count());
    }

    public function test_envia_a_conta_inteira_para_a_maquininha_escolhida(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['id' => 'or_abc', 'code' => 'COD1'], 200)]);

        $sessaoMesa = $this->sessaoMesaComConta();
        $maquininhaGarcom = Maquininha::create(['nome' => 'Garçom 1', 'operadora' => 'stone', 'numero_serie' => '555']);

        Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $sessaoMesa->id])
            ->call('abrirModal')
            ->set('maquininhaId', $maquininhaGarcom->id)
            ->call('enviarCobranca')
            ->assertHasNoErrors()
            ->assertSet('status', 'aguardando');

        $stonePedido = StonePedido::sole();
        $this->assertSame($sessaoMesa->id, $stonePedido->stp_sessao_mesa_id);
        $this->assertSame($maquininhaGarcom->id, $stonePedido->stp_maquininha_id);
        $this->assertSame('sessao_mesa', $stonePedido->stp_origem->value);
        $this->assertSame(80.0, (float) $stonePedido->stp_valor_solicitado);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/orders')
            && $request->data()['poi_payment_settings']['devices_serial_number'] === ['555']);
    }

    public function test_botao_cobrar_some_sem_forma_de_pagamento_integrada_stone(): void
    {
        OpcoesPagamento::query()->update(['opcaopag_stone_integrada' => false]);
        Maquininha::create(['nome' => 'Garçom', 'operadora' => 'stone', 'numero_serie' => '111']);
        $sessaoMesa = $this->sessaoMesaComConta();

        Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $sessaoMesa->id])
            ->assertDontSee('Cobrar na maquininha')
            ->call('abrirModal')
            ->assertSet('modalAberta', false);
    }
}
