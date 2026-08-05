<?php

namespace Tests\Feature\Authorization;

use App\Models\Mesa;
use App\Models\SessaoMesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SessaoMesaPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function userComRole(string $role, string $nome): User
    {
        $user = User::factory()->create(['name_first' => $nome]);
        $user->assignRole($role);

        return $user;
    }

    private function sessao(User $garcom): SessaoMesa
    {
        return SessaoMesa::factory()->create([
            'sessao_mesa_usuario_id' => $garcom->id,
            'sessao_mesa_mesa_id' => Mesa::factory()->create(['mesa_nome' => 'Mesa 1'])->id,
        ]);
    }

    public function test_garcom_dono_da_sessao_pode_atualizar(): void
    {
        $garcom = $this->userComRole('Atendente', 'Dono');
        $sessao = $this->sessao($garcom);

        $this->assertTrue(Gate::forUser($garcom)->allows('update', $sessao));
    }

    public function test_outro_atendente_nao_atualiza_sessao_alheia(): void
    {
        $dono = $this->userComRole('Atendente', 'Dono');
        $outro = $this->userComRole('Atendente', 'Outro');
        $sessao = $this->sessao($dono);

        $this->assertFalse(Gate::forUser($outro)->allows('update', $sessao));
    }

    public function test_gerente_atualiza_qualquer_sessao_de_mesa(): void
    {
        $dono = $this->userComRole('Atendente', 'Dono');
        $gerente = $this->userComRole('Gerente', 'Gerente');
        $sessao = $this->sessao($dono);

        $this->assertTrue(Gate::forUser($gerente)->allows('update', $sessao));
    }
}
