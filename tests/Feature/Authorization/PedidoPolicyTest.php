<?php

namespace Tests\Feature\Authorization;

use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PedidoPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // cancel:pedido, deliver:pedido etc. só existem via seeder — role já vem
        // das migrations, mas as permissions de negócio precisam disso aqui.
        $this->seed(PermissionSeeder::class);
    }

    private function pedido(?User $garcom = null, ?User $entregador = null): Pedido
    {
        return Pedido::factory()->create([
            'pedido_usuario_garcom_id' => $garcom?->id,
            'pedido_usuario_entrega_id' => $entregador?->id,
        ]);
    }

    private function userComRole(string $role, string $nome): User
    {
        $user = User::factory()->create(['name_first' => $nome]);
        $user->assignRole($role);

        return $user;
    }

    public function test_atendente_dono_do_pedido_pode_cancelar(): void
    {
        $atendente = $this->userComRole('Atendente', 'Dono');
        $pedido = $this->pedido(garcom: $atendente);

        $this->assertTrue(Gate::forUser($atendente)->allows('cancel', $pedido));
    }

    public function test_atendente_nao_pode_cancelar_pedido_de_outro(): void
    {
        $dono = $this->userComRole('Atendente', 'Dono');
        $outro = $this->userComRole('Atendente', 'Outro');
        $pedido = $this->pedido(garcom: $dono);

        $this->assertFalse(Gate::forUser($outro)->allows('cancel', $pedido));
    }

    public function test_gerente_cancela_pedido_que_nao_e_seu(): void
    {
        $dono = $this->userComRole('Atendente', 'Dono');
        $gerente = $this->userComRole('Gerente', 'Gerente');
        $pedido = $this->pedido(garcom: $dono);

        $this->assertTrue(Gate::forUser($gerente)->allows('cancel', $pedido));
    }

    public function test_admin_cancela_qualquer_pedido_via_bypass(): void
    {
        $dono = $this->userComRole('Atendente', 'Dono');
        $admin = $this->userComRole('Admin', 'Admin');
        $pedido = $this->pedido(garcom: $dono);

        $this->assertTrue(Gate::forUser($admin)->allows('cancel', $pedido));
    }

    public function test_entregador_so_marca_como_entregue_pedido_atribuido_a_ele(): void
    {
        $entregadorDono = $this->userComRole('Entregador', 'EntDono');
        $outroEntregador = $this->userComRole('Entregador', 'EntOutro');
        $pedido = $this->pedido(entregador: $entregadorDono);

        $this->assertTrue(Gate::forUser($entregadorDono)->allows('deliver', $pedido));
        $this->assertFalse(Gate::forUser($outroEntregador)->allows('deliver', $pedido));
    }

    public function test_qualquer_entregador_pode_tentar_aceitar_uma_entrega_ainda_sem_dono(): void
    {
        $entregador = $this->userComRole('Entregador', 'Ent');
        $pedido = $this->pedido();

        $this->assertTrue(Gate::forUser($entregador)->allows('claim', $pedido));
    }

    public function test_atendente_nao_pode_tentar_aceitar_entrega(): void
    {
        $atendente = $this->userComRole('Atendente', 'At');
        $pedido = $this->pedido();

        $this->assertFalse(Gate::forUser($atendente)->allows('claim', $pedido));
    }
}
