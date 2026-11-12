<?php

namespace Tests\Feature;

use App\Enums\StonePedidoStatus;
use App\Filament\Resources\StonePedidos\Pages\ManageStonePedidos;
use App\Models\Maquininha;
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
 * Cliente trocou a forma de pagamento na entrega (ex.: débito → dinheiro): o
 * pedido segue listado na maquininha e o back office precisa retirá-lo de lá.
 */
class StonePedidoCancelarNaStoneTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private Maquininha $sunmi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->sunmi = $this->maquininhaConnect('Sunmi', '111');
    }

    private function cobranca(StonePedidoStatus $status, array $atributos = []): StonePedido
    {
        return $this->cobrancaDoPedido($this->pedidoComTotal(80), $this->sunmi, ['stp_status' => $status] + $atributos);
    }

    private function cancelamentosEnviados(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/closed')
            && $request['status'] === 'canceled')->count();
    }

    public function test_cancela_aguardando_na_stone_e_marca_cancelado(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $cobranca = $this->cobranca(StonePedidoStatus::Aguardando, ['stp_order_id' => 'or_a']);

        Livewire::test(ManageStonePedidos::class)
            ->callAction(TestAction::make('cancelarPedido')->table($cobranca))
            ->assertNotified('Pedido cancelado na Stone.');

        $this->assertSame(StonePedidoStatus::Cancelado, $cobranca->fresh()->stp_status);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/or_a/closed'));
    }

    public function test_cobranca_marcada_como_falha_depois_de_2h_ainda_pode_ser_cancelada(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $cobranca = $this->cobranca(StonePedidoStatus::Falha, ['stp_order_id' => 'or_f']);

        Livewire::test(ManageStonePedidos::class)
            ->assertTableActionVisible('cancelarPedido', $cobranca)
            ->callAction(TestAction::make('cancelarPedido')->table($cobranca));

        $this->assertSame(StonePedidoStatus::Cancelado, $cobranca->fresh()->stp_status);
        $this->assertSame(1, $this->cancelamentosEnviados());
    }

    public function test_resultado_desconhecido_com_order_id_pode_ser_cancelado(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $cobranca = $this->cobranca(StonePedidoStatus::Indeterminado, ['stp_order_id' => 'or_i']);

        Livewire::test(ManageStonePedidos::class)
            ->callAction(TestAction::make('cancelarPedido')->table($cobranca));

        $this->assertSame(StonePedidoStatus::Cancelado, $cobranca->fresh()->stp_status);
    }

    public function test_acao_nao_aparece_para_o_que_nao_esta_aberto_na_maquininha(): void
    {
        $semOrder = $this->cobranca(StonePedidoStatus::Falha, ['stp_order_id' => null]);
        $pago = $this->cobranca(StonePedidoStatus::Pago, ['stp_valor_pago' => 80]);
        $cancelado = $this->cobranca(StonePedidoStatus::Cancelado);
        $fechado = $this->cobranca(StonePedidoStatus::Falha, ['stp_fechado_em' => now()]);

        $tela = Livewire::test(ManageStonePedidos::class);

        foreach ([$semOrder, $pago, $cancelado, $fechado] as $registro) {
            $tela->assertTableActionHidden('cancelarPedido', $registro);
        }
    }

    public function test_recusa_da_stone_mantem_o_status_e_avisa(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'Pedido já possui cobrança paga'], 422)]);
        $cobranca = $this->cobranca(StonePedidoStatus::Aguardando, ['stp_order_id' => 'or_r']);

        Livewire::test(ManageStonePedidos::class)
            ->callAction(TestAction::make('cancelarPedido')->table($cobranca))
            ->assertNotified('Falha ao cancelar o pedido');

        $this->assertSame(StonePedidoStatus::Aguardando, $cobranca->fresh()->stp_status);
    }

    public function test_pagamento_que_chega_durante_o_cancelamento_nao_e_sobrescrito(): void
    {
        $cobranca = $this->cobranca(StonePedidoStatus::Aguardando, ['stp_order_id' => 'or_p']);
        Http::fake(function (Request $request) use ($cobranca) {
            StonePedido::whereKey($cobranca->id)->update(['stp_status' => StonePedidoStatus::Pago->value, 'stp_valor_pago' => 80]);

            return Http::response([], 200);
        });

        Livewire::test(ManageStonePedidos::class)
            ->callAction(TestAction::make('cancelarPedido')->table($cobranca));

        $this->assertSame(StonePedidoStatus::Pago, $cobranca->fresh()->stp_status);
    }

    public function test_cancelamento_em_lote_cancela_as_abertas_e_ignora_as_demais(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $aguardando = $this->cobranca(StonePedidoStatus::Aguardando, ['stp_order_id' => 'or_1']);
        $falha = $this->cobranca(StonePedidoStatus::Falha, ['stp_order_id' => 'or_2']);
        $pago = $this->cobranca(StonePedidoStatus::Pago, ['stp_order_id' => 'or_3', 'stp_valor_pago' => 80]);

        Livewire::test(ManageStonePedidos::class)
            ->selectTableRecords([$aguardando->id, $falha->id, $pago->id])
            ->callAction(TestAction::make('cancelarPedidosStone')->table()->bulk())
            ->assertNotified('2 pedido(s) cancelado(s) na Stone.');

        $this->assertSame(StonePedidoStatus::Cancelado, $aguardando->fresh()->stp_status);
        $this->assertSame(StonePedidoStatus::Cancelado, $falha->fresh()->stp_status);
        $this->assertSame(StonePedidoStatus::Pago, $pago->fresh()->stp_status);
        $this->assertSame(2, $this->cancelamentosEnviados());
    }

    public function test_filtro_abertas_na_maquininha_lista_so_o_que_pode_ser_cancelado(): void
    {
        $aberta = $this->cobranca(StonePedidoStatus::Falha, ['stp_order_id' => 'or_x']);
        $paga = $this->cobranca(StonePedidoStatus::Pago, ['stp_valor_pago' => 80]);

        Livewire::test(ManageStonePedidos::class)
            ->filterTable('abertas_na_maquininha')
            ->assertCanSeeTableRecords([$aberta])
            ->assertCanNotSeeTableRecords([$paga]);
    }
}
