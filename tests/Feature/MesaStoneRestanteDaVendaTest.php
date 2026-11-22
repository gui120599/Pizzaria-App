<?php

namespace Tests\Feature;

use App\Enums\StonePedidoOrigem;
use App\Enums\StonePedidoStatus;
use App\Livewire\MesaStoneCobranca;
use App\Models\Caixa;
use App\Models\Maquininha;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CenarioStone;
use Tests\TestCase;

/**
 * Depois do primeiro pagamento parcial o webhook lança os itens da mesa numa
 * venda aberta e a conta da mesa zera: o painel do garçom precisa continuar
 * mostrando o que falta e permitir cobrar o restante (cartão ou PIX).
 */
class MesaStoneRestanteDaVendaTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private Maquininha $sunmi;

    private SessaoMesa $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $user = User::factory()->admin()->create(['name_first' => 'Garçom']);
        $this->actingAs($user);
        $this->sunmi = $this->maquininhaConnect('Sunmi', '111');

        SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $mesa = Mesa::create(['mesa_nome' => 'Mesa 5', 'mesa_status' => 'OCUPADA']);
        $this->sessao = SessaoMesa::create(['sessao_mesa_mesa_id' => $mesa->id, 'sessao_mesa_usuario_id' => $user->id, 'sessao_mesa_status' => 'ABERTA']);
        $mesa->update(['mesa_sessao_atual_id' => $this->sessao->id]);
        $pedido = $this->pedidoComTotal(2, 'ABERTO');
        $pedido->update(['pedido_sessao_mesa_id' => $this->sessao->id]);
    }

    /** @return array<int, Request> */
    private function postsDeCriacao(): array
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/orders'))
            ->map(fn (array $par) => $par[0])
            ->values()
            ->all();
    }

    private function chargePaid(string $orderId, int $centavos, string $hook): void
    {
        $this->postJson('/api/webhook/stone-connect', [
            'id' => $hook,
            'type' => 'charge.paid',
            'created_at' => '2026-11-22T18:35:00Z',
            'data' => [
                'id' => 'ch_'.$hook, 'code' => '1', 'amount' => $centavos, 'paid_amount' => $centavos, 'status' => 'paid',
                'payment_method' => 'credit_card',
                'order' => ['id' => $orderId, 'code' => 'X', 'amount' => $centavos, 'closed' => false, 'status' => 'pending', 'metadata' => []],
                'metadata' => ['scheme_name' => 'Visa', 'authorization_code' => 'A1'],
            ],
        ])->assertOk();
    }

    private function enviarParte(string $valor): void
    {
        Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $this->sessao->id])
            ->call('abrirModal')
            ->set('maquininhaId', $this->sunmi->id)
            ->set('valor', $valor)
            ->call('enviarCobranca')
            ->assertSet('status', 'aguardando');
    }

    public function test_depois_do_pagamento_parcial_o_painel_mostra_o_restante_e_oferece_cobrar(): void
    {
        $this->fakeStoneCriaPedidos('or_parte_1');
        $this->enviarParte('1,00');
        $this->chargePaid('or_parte_1', 100, 'hook_parte_1');

        $venda = Venda::sole();
        $this->assertSame('INICIADA', $venda->venda_status);
        $this->assertSame(1.0, (float) $venda->venda_valor_pago);

        Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $this->sessao->id])
            ->assertSee('Pago na maquininha: R$ 1,00 de R$ 2,00')
            ->assertSee('Falta')
            ->assertSee("venda #{$venda->id}")
            ->assertSee('Cobrar na maquininha')
            ->call('abrirModal')
            ->assertSet('status', 'form')
            ->assertSet('valor', '1,00')
            ->assertSee("Venda #{$venda->id}: faltam");
    }

    public function test_cobrar_o_restante_paga_na_mesma_venda_e_finaliza_a_conta_da_mesa(): void
    {
        $this->fakeStoneCriaPedidos('or_parte_1', 'or_parte_2');
        $this->enviarParte('1,00');
        $this->chargePaid('or_parte_1', 100, 'hook_parte_1');
        $venda = Venda::sole();

        Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $this->sessao->id])
            ->call('abrirModal')
            ->set('maquininhaId', $this->sunmi->id)
            ->call('enviarCobranca')
            ->assertSet('status', 'aguardando');

        $segunda = StonePedido::orderByDesc('id')->first();
        $this->assertSame($venda->id, $segunda->stp_venda_id);
        $this->assertSame(StonePedidoOrigem::SessaoMesa, $segunda->stp_origem);
        $this->assertSame($this->sessao->id, $segunda->stp_sessao_mesa_id);
        $this->assertSame(1.0, (float) $segunda->stp_valor_solicitado);
        $this->assertSame(100, $this->postsDeCriacao()[1]['items'][0]['amount']);

        $this->chargePaid('or_parte_2', 100, 'hook_parte_2');

        $this->assertSame(1, Venda::count());
        $this->assertSame('FINALIZADA', $venda->fresh()->venda_status);
        $this->assertSame(2.0, (float) $venda->fresh()->venda_valor_pago);
        $this->assertSame('FINALIZADA', $this->sessao->fresh()->sessao_mesa_status);
        $this->assertSame(StonePedidoStatus::Pago, $segunda->fresh()->stp_status);
    }

    public function test_valor_acima_do_restante_da_venda_e_recusado(): void
    {
        $this->fakeStoneCriaPedidos('or_parte_1');
        $this->enviarParte('1,00');
        $this->chargePaid('or_parte_1', 100, 'hook_parte_1');
        Http::fake();

        Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $this->sessao->id])
            ->call('abrirModal')
            ->set('maquininhaId', $this->sunmi->id)
            ->set('valor', '5,00')
            ->call('enviarCobranca')
            ->assertSet('status', 'erro')
            ->assertSee('passa do saldo devido');

        $this->assertSame(1, StonePedido::count());
    }

    public function test_venda_quitada_ou_sem_pagamento_stone_nao_mostra_o_aviso_de_restante(): void
    {
        Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $this->sessao->id])
            ->assertDontSee('Pago na maquininha');
    }

    public function test_pedido_avulso_tambem_recupera_o_restante_depois_do_pagamento_parcial(): void
    {
        $this->fakeStoneCriaPedidos('or_av_1');
        $avulso = $this->pedidoComTotal(10, 'PRONTO');

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $avulso->id])
            ->call('abrirModal')->set('maquininhaId', $this->sunmi->id)->set('valor', '4')->call('enviarCobranca');
        $this->chargePaid('or_av_1', 400, 'hook_av_1');

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $avulso->id])
            ->assertSee('Pago na maquininha: R$ 4,00 de R$ 10,00')
            ->assertSee('Cobrar na maquininha')
            ->call('abrirModal')
            ->assertSet('valor', '6,00');

        $this->assertNotNull(Pedido::find($avulso->id));
    }
}
