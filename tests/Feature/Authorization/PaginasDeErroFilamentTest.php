<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginasDeErroFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_403_dentro_do_painel_usa_layout_do_filament(): void
    {
        $atendente = User::factory()->create(['name_first' => 'Atendente']);
        $atendente->assignRole('Atendente');

        $response = $this->actingAs($atendente)->get('/admin/users');

        $response->assertForbidden();
        $response->assertSee('Acesso negado');
        $response->assertSee('fi-simple-layout', false);
    }

    public function test_404_dentro_do_painel_usa_layout_do_filament(): void
    {
        $admin = User::factory()->create(['name_first' => 'Admin']);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)->get('/admin/rota-que-nao-existe');

        $response->assertNotFound();
        $response->assertSee('Página não encontrada');
        $response->assertSee('fi-simple-layout', false);
    }

    public function test_403_fora_do_painel_continua_usando_layout_blade_legado(): void
    {
        $atendente = User::factory()->create(['name_first' => 'Atendente']);
        $atendente->assignRole('Atendente');

        $response = $this->actingAs($atendente)->get(route('sessao_caixa'));

        $response->assertForbidden();
        $response->assertDontSee('fi-simple-layout', false);
    }
}
