<?php

namespace Tests\Feature\Authorization;

use App\Models\Caixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SessaoCaixaPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    private function userComRole(string $role, string $nome): User
    {
        $user = User::factory()->create(['name_first' => $nome]);
        $user->assignRole($role);

        return $user;
    }

    private function sessao(User $dono): SessaoCaixa
    {
        return SessaoCaixa::factory()->create([
            'sessaocaixa_user_id' => $dono->id,
            'sessaocaixa_caixa_id' => Caixa::factory()->create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_data_hora_abertura' => now(),
        ]);
    }

    public function test_role_caixa_pode_abrir_sessao(): void
    {
        $caixa = $this->userComRole('Caixa', 'Operador');

        $this->assertTrue(Gate::forUser($caixa)->allows('open', SessaoCaixa::class));
    }

    public function test_atendente_nao_pode_abrir_sessao_de_caixa(): void
    {
        $atendente = $this->userComRole('Atendente', 'At');

        $this->assertFalse(Gate::forUser($atendente)->allows('open', SessaoCaixa::class));
    }

    public function test_dono_da_sessao_pode_fechar(): void
    {
        $dono = $this->userComRole('Caixa', 'Dono');
        $sessao = $this->sessao($dono);

        $this->assertTrue(Gate::forUser($dono)->allows('close', $sessao));
    }

    public function test_outro_operador_nao_fecha_sessao_alheia(): void
    {
        $dono = $this->userComRole('Caixa', 'Dono');
        $outro = $this->userComRole('Caixa', 'Outro');
        $sessao = $this->sessao($dono);

        $this->assertFalse(Gate::forUser($outro)->allows('close', $sessao));
    }

    public function test_ninguem_alem_do_admin_exclui_sessao_de_caixa(): void
    {
        $dono = $this->userComRole('Caixa', 'Dono');
        $sessao = $this->sessao($dono);

        $this->assertFalse(Gate::forUser($dono)->allows('delete', $sessao));

        $admin = $this->userComRole('Admin', 'Admin');
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $sessao));
    }
}
