<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PainelAcessoTest extends TestCase
{
    use RefreshDatabase;

    private function canAccess(User $user): bool
    {
        return $user->canAccessPanel(Filament::getPanel('admin'));
    }

    public function test_usuario_sem_role_nao_acessa_o_painel(): void
    {
        $user = User::factory()->create(['name_first' => 'SemRole']);

        $this->assertFalse($this->canAccess($user));
    }

    public function test_cliente_nao_acessa_o_painel(): void
    {
        $user = User::factory()->create(['name_first' => 'Cliente']);
        $user->assignRole('Cliente');

        $this->assertFalse($this->canAccess($user));
    }

    #[DataProvider('rolesOperacionais')]
    public function test_roles_operacionais_acessam_o_painel(string $role): void
    {
        $user = User::factory()->create(['name_first' => $role]);
        $user->assignRole($role);

        $this->assertTrue($this->canAccess($user));
    }

    public static function rolesOperacionais(): array
    {
        return [
            ['Admin'],
            ['Gerente'],
            ['Atendente'],
            ['Caixa'],
            ['Entregador'],
        ];
    }

    public function test_registro_publico_no_cardapio_nao_ganha_acesso_ao_painel(): void
    {
        $response = $this->post('/register', [
            'name' => 'Cliente Cardapio',
            'name_first' => 'Cliente Cardapio',
            'email' => 'cliente-cardapio@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect();

        $user = User::where('email', 'cliente-cardapio@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('Cliente'));
        $this->assertFalse($this->canAccess($user));
    }
}
