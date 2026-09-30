<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\Pedido;
use App\Models\User;
use App\Services\PedidoStatusService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O service delega a decisão à PedidoPolicy. O que importa aqui é que a
 * checagem realmente acontece quando um ator é informado — a tela nova é
 * Livewire, e chamadas Livewire não passam pelo middleware da rota.
 */
class PedidoStatusServiceAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    private PedidoStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->service = app(PedidoStatusService::class);
    }

    private function pedido(StatusPedidoEnum $status, array $extras = []): Pedido
    {
        return Pedido::create($extras + [
            'pedido_status' => $status->value,
            'pedido_datahora_abertura' => now(),
        ]);
    }

    public function test_usuario_sem_permissao_nao_avanca_pedido(): void
    {
        $semPermissao = User::factory()->create(['name_first' => 'Zé']);

        $this->expectException(AuthorizationException::class);

        $this->service->avancar($this->pedido(StatusPedidoEnum::ABERTO), $semPermissao);
    }

    public function test_usuario_sem_permissao_nao_aceita_pedido(): void
    {
        $semPermissao = User::factory()->create(['name_first' => 'Zé']);

        $this->expectException(AuthorizationException::class);

        $this->service->aceitar($this->pedido(StatusPedidoEnum::ABERTO), $semPermissao);
    }

    public function test_gerente_cancela_pedido_de_qualquer_atendente(): void
    {
        $gerente = User::factory()->create(['name_first' => 'Gerente']);
        $gerente->assignRole('Gerente');

        $outroAtendente = User::factory()->create(['name_first' => 'Colega']);

        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO, [
            'pedido_usuario_garcom_id' => $outroAtendente->id,
        ]);

        $pedido = $this->service->cancelar($pedido, $gerente, MotivoCancelamentoEnum::OUTRO);

        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $pedido->pedido_status);
    }

    /** PedidoPolicy::cancel — Atendente só cancela o que ele mesmo abriu. */
    public function test_atendente_nao_cancela_pedido_aberto_por_outro(): void
    {
        $atendente = User::factory()->create(['name_first' => 'Atendente']);
        $atendente->assignRole('Atendente');

        $outro = User::factory()->create(['name_first' => 'Colega']);

        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO, [
            'pedido_usuario_garcom_id' => $outro->id,
        ]);

        $this->expectException(AuthorizationException::class);

        $this->service->cancelar($pedido, $atendente, MotivoCancelamentoEnum::OUTRO);
    }

    public function test_atendente_cancela_o_proprio_pedido(): void
    {
        $atendente = User::factory()->create(['name_first' => 'Atendente']);
        $atendente->assignRole('Atendente');

        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO, [
            'pedido_usuario_garcom_id' => $atendente->id,
        ]);

        $pedido = $this->service->cancelar($pedido, $atendente, MotivoCancelamentoEnum::OUTRO);

        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $pedido->pedido_status);
    }
}
