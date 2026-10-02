<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Pedido;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tabela de pedidos do Filament: clique na linha abre o atendimento, copiar o
 * link de acompanhamento e cancelar com motivo.
 */
class PedidosTableAcoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function pedido(StatusPedidoEnum $status): Pedido
    {
        return Pedido::create(['pedido_status' => $status->value, 'pedido_datahora_abertura' => now()]);
    }

    public function test_clique_na_linha_abre_o_pedido_no_atendimento(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::ABERTO);

        Livewire::test(ListPedidos::class)
            ->assertSeeHtml(AtenderPedido::getUrl(['pedido' => $pedido]));
    }

    public function test_cancelar_grava_o_motivo(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO);

        Livewire::test(ListPedidos::class)
            ->callAction(TestAction::make('cancelarPedido')->table($pedido), ['motivo' => MotivoCancelamentoEnum::DEMORA->value])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $pedido->refresh();
        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $pedido->pedido_status);
        $this->assertSame(MotivoCancelamentoEnum::DEMORA->value, $pedido->getRawOriginal('pedido_motivo_cancelamento'));
    }

    public function test_pedido_ainda_nao_aceito_e_rejeitado_com_o_motivo(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::ABERTO);

        Livewire::test(ListPedidos::class)
            ->callAction(TestAction::make('cancelarPedido')->table($pedido), ['motivo' => MotivoCancelamentoEnum::CLIENTE_DESISTIU->value]);

        $pedido->refresh();
        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $pedido->pedido_status);
        $this->assertSame(MotivoCancelamentoEnum::CLIENTE_DESISTIU->value, $pedido->getRawOriginal('pedido_motivo_cancelamento'));
    }

    public function test_cancelar_exige_motivo(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO);

        Livewire::test(ListPedidos::class)
            ->callAction(TestAction::make('cancelarPedido')->table($pedido), ['motivo' => null])
            ->assertHasFormErrors(['motivo' => 'required']);

        $this->assertSame(StatusPedidoEnum::PREPARANDO->value, $pedido->refresh()->pedido_status);
    }

    public function test_cancelar_fica_oculto_para_pedido_encerrado(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::FINALIZADO);

        Livewire::test(ListPedidos::class)
            ->assertActionHidden(TestAction::make('cancelarPedido')->table($pedido))
            ->assertActionVisible(TestAction::make('copiarLinkAcompanhamento')->table($pedido));
    }
}
