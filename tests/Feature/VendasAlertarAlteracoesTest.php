<?php

namespace Tests\Feature;

use App\Filament\Resources\Vendas\VendaResource;
use App\Models\User;
use App\Models\Venda;
use App\Models\VendaAlteracao;
use App\Notifications\VendaEmAlteracaoNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Venda reaberta para alteração e esquecida: alerta uma vez para quem pode
 * alterar venda finalizada e badge no menu Vendas.
 */
class VendasAlertarAlteracoesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name_first' => 'Ana', 'name' => 'Ana']);
        $this->actingAs($this->admin);
    }

    private function alteracao(string $status = VendaAlteracao::ABERTA, int $horasAtras = 3): VendaAlteracao
    {
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_datahora_iniciada' => now(), 'venda_valor_total' => 40]);

        return VendaAlteracao::forceCreate([
            'venda_id' => $venda->id, 'user_id' => $this->admin->id, 'motivo' => 'item errado', 'status' => $status,
            'antes' => [], 'created_at' => now()->subHours($horasAtras), 'updated_at' => now()->subHours($horasAtras),
        ]);
    }

    public function test_alerta_so_depois_do_prazo_e_uma_vez(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gil', 'name' => 'Gil']);
        $gerente->givePermissionTo(Permission::firstOrCreate(['name' => 'alterar_finalizada:venda', 'guard_name' => 'web']));
        $caixa = User::factory()->caixa()->create(['name_first' => 'Cai', 'name' => 'Cai']);

        $velha = $this->alteracao(horasAtras: 3);
        $recente = $this->alteracao(horasAtras: 1);

        $this->artisan('vendas:alertar-alteracoes-abertas')->assertSuccessful();

        $this->assertNotNull($velha->fresh()->alertada_em);
        $this->assertNull($recente->fresh()->alertada_em);
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(1, $gerente->notifications()->count());
        $this->assertSame(0, $caixa->notifications()->count());
        $this->assertStringContainsString("Venda #{$velha->venda_id} em alteração", $this->admin->notifications()->first()->data['title']);
        $this->assertSame(VendaEmAlteracaoNotification::class, $this->admin->notifications()->first()->type);

        $this->artisan('vendas:alertar-alteracoes-abertas')->assertSuccessful();
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_nao_alerta_alteracao_encerrada_e_o_prazo_e_configuravel(): void
    {
        $this->alteracao(VendaAlteracao::CONCLUIDA, 5);
        $umaHora = $this->alteracao(horasAtras: 1);

        $this->artisan('vendas:alertar-alteracoes-abertas')->assertSuccessful();
        $this->assertSame(0, $this->admin->notifications()->count());

        $this->artisan('vendas:alertar-alteracoes-abertas', ['--horas' => 1])->assertSuccessful();
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertNotNull($umaHora->fresh()->alertada_em);
    }

    public function test_badge_do_menu_conta_vendas_em_alteracao_e_o_comando_esta_agendado(): void
    {
        $this->assertNull(VendaResource::getNavigationBadge());

        $this->alteracao();
        $this->alteracao(VendaAlteracao::DESCARTADA);

        $this->assertSame('1', VendaResource::getNavigationBadge());
        $this->assertSame('warning', VendaResource::getNavigationBadgeColor());

        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'vendas:alertar-alteracoes-abertas'));
        $this->assertNotNull($evento);
        $this->assertSame('*/15 * * * *', $evento->expression);
    }
}
