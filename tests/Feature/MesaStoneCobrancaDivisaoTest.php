<?php

namespace Tests\Feature;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Filament\Resources\StonePedidos\Pages\ManageStonePedidos;
use App\Livewire\MesaStoneCobranca;
use App\Models\Maquininha;
use App\Models\Mesa;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CenarioStone;
use Tests\TestCase;

/**
 * Mesa que divide a conta: uma parte paga na maquininha e outra em dinheiro. O
 * garçom informa só o valor do cartão, e uma cobrança parcialmente paga pode
 * ser encerrada para o restante não ficar listado no POS.
 */
class MesaStoneCobrancaDivisaoTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private Maquininha $sunmi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Garçom']));
        $this->sunmi = $this->maquininhaConnect('Sunmi', '111');
    }

    /** @return array<int, Request> */
    private function postsDeCriacao(): array
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/orders'))
            ->map(fn (array $par) => $par[0])
            ->all();
    }

    private function encerramentosEnviados(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/closed')
            && $request['status'] === 'paid')->count();
    }

    public function test_o_modal_abre_com_o_valor_devido_preenchido(): void
    {
        $pedido = $this->pedidoComTotal(100, 'PRONTO');

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')
            ->assertSet('status', 'form')
            ->assertSet('valor', '100,00')
            ->assertSee('Parte da mesa vai pagar em dinheiro?');
    }

    public function test_envia_so_o_valor_do_cartao_e_deixa_o_resto_para_o_caixa(): void
    {
        $this->fakeStoneCriaPedidos('or_cartao');
        $pedido = $this->pedidoComTotal(100, 'PRONTO');

        $tela = Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')
            ->set('maquininhaId', $this->sunmi->id)
            ->set('valor', '60,00')
            ->call('enviarCobranca')
            ->assertSet('status', 'aguardando');

        $stonePedido = StonePedido::sole();
        $this->assertSame(60.0, (float) $stonePedido->stp_valor_solicitado);
        $this->assertSame(StonePedidoModo::Listado, $stonePedido->stp_modo);

        [$post] = $this->postsDeCriacao();
        $this->assertSame(6000, $post['items'][0]['amount']);
        $this->assertSame(40.0, $tela->instance()->totalAberto);
    }

    public function test_aceita_milhar_virgula_e_ponto_decimal(): void
    {
        $this->fakeStoneCriaPedidos('or_1', 'or_2');
        $grande = $this->pedidoComTotal(2000, 'PRONTO');
        $outro = $this->pedidoComTotal(100, 'PRONTO');

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $grande->id])
            ->call('abrirModal')->set('maquininhaId', $this->sunmi->id)->set('valor', '1.200,50')->call('enviarCobranca');
        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $outro->id])
            ->call('abrirModal')->set('maquininhaId', $this->sunmi->id)->set('valor', '12.5')->call('enviarCobranca');

        $this->assertSame([120050, 1250], array_map(fn (Request $post): int => $post['items'][0]['amount'], $this->postsDeCriacao()));
    }

    public function test_valor_acima_do_devido_e_recusado_sem_chamar_a_stone(): void
    {
        Http::fake();
        $pedido = $this->pedidoComTotal(100, 'PRONTO');

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')
            ->set('maquininhaId', $this->sunmi->id)
            ->set('valor', '150,00')
            ->call('enviarCobranca')
            ->assertSet('status', 'erro')
            ->assertSee('passa do saldo devido');

        $this->assertSame(0, StonePedido::count());
        Http::assertNothingSent();
    }

    public function test_valor_vazio_ou_zero_pede_o_valor(): void
    {
        Http::fake();
        $pedido = $this->pedidoComTotal(100, 'PRONTO');

        foreach (['', '0', 'abc'] as $valor) {
            Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
                ->call('abrirModal')
                ->set('maquininhaId', $this->sunmi->id)
                ->set('valor', $valor)
                ->call('enviarCobranca')
                ->assertHasErrors(['valor']);
        }

        Http::assertNothingSent();
    }

    public function test_depois_de_enviar_uma_parte_da_para_enviar_outro_valor_ate_fechar_a_conta(): void
    {
        $this->fakeStoneCriaPedidos('or_a', 'or_b');
        $pedido = $this->pedidoComTotal(100, 'PRONTO');

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')->set('maquininhaId', $this->sunmi->id)->set('valor', '60')->call('enviarCobranca')
            ->assertSee('Enviar outro valor (faltam R$ 40,00)')
            ->call('novoEnvio')
            ->assertSet('status', 'form')
            ->assertSet('valor', '40,00')
            ->call('enviarCobranca')
            ->assertSet('status', 'aguardando')
            ->assertDontSee('Enviar outro valor');

        $this->assertSame([60.0, 40.0], StonePedido::orderBy('id')->pluck('stp_valor_solicitado')->map(fn ($v) => (float) $v)->all());
    }

    public function test_cobranca_sem_pagamento_oferece_cancelar_e_nao_encerrar(): void
    {
        $pedido = $this->pedidoComTotal(100, 'PRONTO');
        $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_valor_solicitado' => 100]);

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')
            ->assertSet('status', 'aguardando')
            ->assertSee('Cancelar cobrança')
            ->assertDontSee('o restante foi pago em dinheiro');
    }

    public function test_cobranca_parcialmente_paga_mostra_os_valores_e_oferece_encerrar(): void
    {
        $pedido = $this->pedidoComTotal(100, 'PRONTO');
        $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_status' => StonePedidoStatus::PagoParcial, 'stp_valor_solicitado' => 100, 'stp_valor_pago' => 60]);

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')
            ->assertSet('status', 'aguardando')
            ->assertSee('R$ 60,00')
            ->assertSee('R$ 40,00')
            ->assertSee('Encerrar: o restante foi pago em dinheiro')
            ->assertDontSee('Cancelar cobrança');
    }

    public function test_encerrar_fecha_o_pedido_na_stone_e_devolve_o_restante_para_o_caixa(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $pedido = $this->pedidoComTotal(100, 'PRONTO');
        $cobranca = $this->cobrancaDoPedido($pedido, $this->sunmi, [
            'stp_status' => StonePedidoStatus::PagoParcial, 'stp_valor_solicitado' => 100, 'stp_valor_pago' => 60, 'stp_order_id' => 'or_parcial',
        ]);

        $tela = Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')
            ->call('encerrarRestante')
            ->assertSet('status', 'encerrado')
            ->assertSee('Restam')
            ->assertSee('R$ 40,00');

        $cobranca->refresh();
        $this->assertSame(StonePedidoStatus::Pago, $cobranca->stp_status);
        $this->assertSame(60.0, (float) $cobranca->stp_valor_solicitado);
        $this->assertNotNull($cobranca->stp_fechado_em);
        $this->assertSame(1, $this->encerramentosEnviados());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/or_parcial/closed'));
        $this->assertSame(40.0, $tela->instance()->totalAberto);
    }

    public function test_recusa_da_stone_ao_encerrar_mantem_a_cobranca_como_estava(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'Pedido não pode ser fechado'], 422)]);
        $pedido = $this->pedidoComTotal(100, 'PRONTO');
        $cobranca = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_status' => StonePedidoStatus::PagoParcial, 'stp_valor_solicitado' => 100, 'stp_valor_pago' => 60]);

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')
            ->call('encerrarRestante')
            ->assertSet('status', 'erro')
            ->assertSee('Pedido não pode ser fechado');

        $cobranca->refresh();
        $this->assertSame(StonePedidoStatus::PagoParcial, $cobranca->stp_status);
        $this->assertSame(100.0, (float) $cobranca->stp_valor_solicitado);
        $this->assertNull($cobranca->stp_fechado_em);
    }

    public function test_cobranca_que_ja_recebeu_valor_nao_pode_ser_cancelada(): void
    {
        Http::fake();
        $pedido = $this->pedidoComTotal(100, 'PRONTO');
        $cobranca = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_status' => StonePedidoStatus::PagoParcial, 'stp_valor_solicitado' => 100, 'stp_valor_pago' => 60]);

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')
            ->call('cancelarCobranca')
            ->assertSet('status', 'erro')
            ->assertSee('Encerrar cobrança');

        $this->assertSame(StonePedidoStatus::PagoParcial, $cobranca->fresh()->stp_status);
        Http::assertNothingSent();
    }

    public function test_hub_encerra_cobranca_parcialmente_paga_e_esconde_a_acao_nas_demais(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $parcial = $this->cobrancaDoPedido($this->pedidoComTotal(100), $this->sunmi, ['stp_status' => StonePedidoStatus::PagoParcial, 'stp_valor_solicitado' => 100, 'stp_valor_pago' => 60]);
        $aguardando = $this->cobrancaDoPedido($this->pedidoComTotal(50), $this->sunmi);
        $semPagamento = $this->cobrancaDoPedido($this->pedidoComTotal(30), $this->sunmi, ['stp_status' => StonePedidoStatus::PagoParcial, 'stp_valor_pago' => 0]);

        Livewire::test(ManageStonePedidos::class)
            ->assertTableActionHidden('encerrarRestante', $aguardando)
            ->assertTableActionHidden('encerrarRestante', $semPagamento)
            ->assertTableActionVisible('encerrarRestante', $parcial)
            ->callAction(TestAction::make('encerrarRestante')->table($parcial))
            ->assertNotified('Cobrança encerrada na Stone.');

        $this->assertSame(StonePedidoStatus::Pago, $parcial->fresh()->stp_status);
        $this->assertSame(60.0, (float) $parcial->fresh()->stp_valor_solicitado);
    }

    public function test_conta_de_mesa_envia_so_a_parte_do_cartao_e_pode_enviar_o_resto_depois(): void
    {
        $this->fakeStoneCriaPedidos('or_mesa_1', 'or_mesa_2');
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 4', 'mesa_status' => 'OCUPADA']);
        $sessao = SessaoMesa::create(['sessao_mesa_mesa_id' => $mesa->id, 'sessao_mesa_usuario_id' => User::first()->id, 'sessao_mesa_status' => 'ABERTA']);
        $mesa->update(['mesa_sessao_atual_id' => $sessao->id]);
        $pedido = $this->pedidoComTotal(120, 'ENTREGUE');
        $pedido->update(['pedido_sessao_mesa_id' => $sessao->id]);

        $tela = Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $sessao->id])
            ->call('abrirModal')
            ->assertSet('valor', '120,00')
            ->set('maquininhaId', $this->sunmi->id)
            ->set('valor', '80,00')
            ->call('enviarCobranca')
            ->assertSet('status', 'aguardando');

        $stonePedido = StonePedido::sole();
        $this->assertSame($sessao->id, $stonePedido->stp_sessao_mesa_id);
        $this->assertSame(80.0, (float) $stonePedido->stp_valor_solicitado);
        $this->assertSame(40.0, $tela->instance()->totalAberto);
    }
}
