<?php

namespace Tests\Feature;

use App\Filament\Widgets\FinanceiroStatsOverview;
use App\Models\User;
use App\Models\Venda;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class FinanceiroStatsOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    /** @return array<string, Stat> */
    private function stats(): array
    {
        $widget = Livewire::test(FinanceiroStatsOverview::class, ['pageFilters' => ['inicio' => '2026-10-01', 'fim' => '2026-10-31']])->instance();

        return collect((new ReflectionMethod($widget, 'getStats'))->invoke($widget))
            ->keyBy(fn (Stat $stat) => (string) $stat->getLabel())
            ->all();
    }

    private function venda(string $finalizadaEm, float $valor): Venda
    {
        return Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => $valor,
            'venda_datahora_finalizada' => $finalizadaEm,
        ]);
    }

    public function test_faturamento_compara_com_o_periodo_anterior(): void
    {
        $this->venda('2026-09-10 12:00:00', 100);
        $this->venda('2026-10-10 12:00:00', 100);
        $this->venda('2026-10-11 12:00:00', 100);

        $stats = $this->stats();

        $this->assertSame('R$ 200,00', $stats['Faturamento']->getValue());
        $this->assertStringContainsString('▲ 100,0% vs. período anterior', (string) $stats['Faturamento']->getDescription());
        $this->assertStringContainsString('= 0,0% vs. período anterior', (string) $stats['Ticket médio']->getDescription());
    }

    public function test_queda_mostra_seta_para_baixo_e_sem_base_mostra_travessao(): void
    {
        $this->venda('2026-09-10 12:00:00', 100);
        $this->venda('2026-09-11 12:00:00', 100);
        $this->venda('2026-10-10 12:00:00', 100);

        $stats = $this->stats();

        $this->assertStringContainsString('▼ 50,0% vs. período anterior', (string) $stats['Faturamento']->getDescription());
        // Sem cancelamentos em setembro não há percentual de variação.
        $this->assertStringContainsString('— vs. período anterior', (string) $stats['Cancelamentos']->getDescription());
    }
}
