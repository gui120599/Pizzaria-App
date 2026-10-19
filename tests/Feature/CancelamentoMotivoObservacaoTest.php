<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Pedido;
use App\Models\User;
use App\Services\PedidoStatusService;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Motivo "Outro" não diz o que houve: o cancelamento exige texto, no service
 * (que tem outros chamadores) e no formulário.
 */
class CancelamentoMotivoObservacaoTest extends TestCase
{
    use RefreshDatabase;

    private PedidoStatusService $service;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->service = app(PedidoStatusService::class);
        $this->gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $this->gerente->givePermissionTo([Permission::findOrCreate('view_any:pedido'), Permission::findOrCreate('view:pedido')]);
    }

    private function pedido(string $status): Pedido
    {
        return Pedido::create(['pedido_status' => $status, 'pedido_valor_total' => 20.0, 'pedido_datahora_abertura' => now()]);
    }

    public function test_service_recusa_outro_sem_texto_em_cancelar_rejeitar_e_cancelar_entregue(): void
    {
        $chamadas = [
            fn () => $this->service->cancelar($this->pedido('PREPARANDO'), $this->gerente, MotivoCancelamentoEnum::OUTRO),
            fn () => $this->service->rejeitar($this->pedido('ABERTO'), $this->gerente, MotivoCancelamentoEnum::OUTRO, '   '),
            fn () => $this->service->cancelarEntregue($this->pedido('ENTREGUE'), $this->gerente, MotivoCancelamentoEnum::OUTRO),
        ];

        foreach ($chamadas as $chamada) {
            try {
                $chamada();
                $this->fail('Esperava TransicaoPedidoInvalidaException.');
            } catch (TransicaoPedidoInvalidaException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Pedido::where('pedido_status', 'CANCELADO')->count());
    }

    public function test_service_grava_a_observacao_do_outro(): void
    {
        $pedido = $this->service->cancelar($this->pedido('PREPARANDO'), $this->gerente, MotivoCancelamentoEnum::OUTRO, 'Forno quebrou');

        $this->assertSame('Forno quebrou', $pedido->fresh()->pedido_motivo_cancelamento_observacao);
    }

    public function test_outros_motivos_nao_exigem_texto(): void
    {
        $pedido = $this->service->cancelar($this->pedido('PREPARANDO'), $this->gerente, MotivoCancelamentoEnum::DEMORA);

        $this->assertSame('CANCELADO', $pedido->fresh()->pedido_status);
        $this->assertNull($pedido->fresh()->pedido_motivo_cancelamento_observacao);
    }

    public function test_acao_da_tabela_exige_observacao_no_outro(): void
    {
        $this->actingAs($this->gerente);
        $pedido = $this->pedido('PREPARANDO');

        Livewire::test(ListPedidos::class)
            ->callAction(TestAction::make('cancelarPedido')->table($pedido), ['motivo' => MotivoCancelamentoEnum::OUTRO->value])
            ->assertHasFormErrors(['observacao']);

        $this->assertSame('PREPARANDO', $pedido->fresh()->pedido_status);

        Livewire::test(ListPedidos::class)
            ->callAction(TestAction::make('cancelarPedido')->table($pedido), [
                'motivo' => MotivoCancelamentoEnum::OUTRO->value,
                'observacao' => 'Cliente não atendeu o telefone',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('Cliente não atendeu o telefone', $pedido->fresh()->pedido_motivo_cancelamento_observacao);
    }
}
