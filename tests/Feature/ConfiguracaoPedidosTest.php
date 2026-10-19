<?php

namespace Tests\Feature;

use App\Filament\Pages\ConfiguracaoPedidos;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConfiguracaoPedidosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste']);
    }

    public function test_admin_salva_a_validade_do_link(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(ConfiguracaoPedidos::class)
            ->fillForm(['empresa_validade_link_ticket_horas' => 48])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(48, Empresa::first()->empresa_validade_link_ticket_horas);
    }

    public function test_valor_fora_do_intervalo_gera_erro_de_validacao(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        foreach ([0, 721] as $invalido) {
            Livewire::test(ConfiguracaoPedidos::class)
                ->fillForm(['empresa_validade_link_ticket_horas' => $invalido])
                ->call('save')
                ->assertHasFormErrors(['empresa_validade_link_ticket_horas']);
        }

        $this->assertSame(24, Empresa::first()->empresa_validade_link_ticket_horas);
    }

    public function test_gerente_e_atendente_nao_acessam(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->assertTrue(ConfiguracaoPedidos::canAccess());

        foreach (['gerente', 'atendente'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create(['name_first' => 'Usuario']));

            $this->assertFalse(ConfiguracaoPedidos::canAccess());
        }
    }
}
