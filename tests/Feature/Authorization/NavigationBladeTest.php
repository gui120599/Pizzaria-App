<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationBladeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // view:relatorio_financeiro (Gerente) só existe via seeder.
        $this->seed(PermissionSeeder::class);
    }

    public function test_atendente_nao_ve_sessoes_de_caixa_nem_relatorios(): void
    {
        $atendente = User::factory()->create(['name_first' => 'Atendente']);
        $atendente->assignRole('Atendente');

        $response = $this->actingAs($atendente)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Sessões de Caixa');
        // Não usa o texto "Relatórios" — existe um comentário HTML fixo com esse
        // texto fora do @can (inofensivo, invisível pro usuário, mas apareceria
        // no assertDontSee). O link real de "Vendas Mensal" é inequívoco.
        $response->assertDontSee(route('venda.relatorioMensal'));
    }

    public function test_caixa_ve_sessoes_de_caixa_mas_nao_relatorios(): void
    {
        $caixa = User::factory()->create(['name_first' => 'Caixa']);
        $caixa->assignRole('Caixa');

        $response = $this->actingAs($caixa)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Sessões de Caixa');
        // Não usa o texto "Relatórios" — existe um comentário HTML fixo com esse
        // texto fora do @can (inofensivo, invisível pro usuário, mas apareceria
        // no assertDontSee). O link real de "Vendas Mensal" é inequívoco.
        $response->assertDontSee(route('venda.relatorioMensal'));
    }

    public function test_gerente_ve_relatorios(): void
    {
        $gerente = User::factory()->create(['name_first' => 'Gerente']);
        $gerente->assignRole('Gerente');

        $response = $this->actingAs($gerente)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(route('venda.relatorioMensal'));
        $response->assertSee('Sessões de Caixa');
    }

    public function test_admin_ve_tudo(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Sessões de Caixa');
        $response->assertSee(route('venda.relatorioMensal'));
        $response->assertSee('Usuários');
    }
}
