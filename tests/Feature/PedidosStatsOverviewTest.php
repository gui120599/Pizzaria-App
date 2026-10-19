<?php

namespace Tests\Feature;

use App\Filament\Widgets\PedidosStatsOverview;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class PedidosStatsOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, Stat>
     */
    private function stats(array $filtros = []): array
    {
        $widget = Livewire::test(PedidosStatsOverview::class, ['pageFilters' => $filtros + ['inicio' => '2026-10-01', 'fim' => '2026-10-31']])->instance();

        return collect((new ReflectionMethod($widget, 'getStats'))->invoke($widget))
            ->keyBy(fn (Stat $stat) => (string) $stat->getLabel())
            ->all();
    }

    private function entregue(array $extra = []): Pedido
    {
        return Pedido::create($extra + [
            'pedido_status' => 'FINALIZADO',
            'pedido_valor_total' => 20,
            'pedido_datahora_abertura' => '2026-10-10 12:00:00',
            'pedido_datahora_entrega' => '2026-10-10 12:30:00',
            'pedido_datahora_finalizado' => '2026-10-10 15:00:00',
        ]);
    }

    public function test_tempo_ate_a_entrega_ignora_o_horario_de_finalizacao(): void
    {
        $this->entregue();

        $stats = $this->stats();

        $this->assertArrayNotHasKey('Tempo de ciclo', $stats);
        $this->assertSame('30 min', $stats['Tempo até a entrega']->getValue());
        $this->assertStringContainsString('Abertura → entregue', (string) $stats['Tempo até a entrega']->getDescription());
    }

    public function test_filtro_de_tipo_de_atendimento_restringe_os_pedidos(): void
    {
        $retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_requer_endereco' => false]);
        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Entrega', 'opcaoentrega_requer_endereco' => true]);
        $this->entregue(['pedido_opcaoentrega_id' => $retirada->id]);
        $this->entregue(['pedido_opcaoentrega_id' => $delivery->id]);
        $this->entregue(['pedido_opcaoentrega_id' => $delivery->id]);

        $this->assertSame('3', $this->stats()['Pedidos']->getValue());
        $this->assertSame('2', $this->stats(['tipos_atendimento' => ['delivery']])['Pedidos']->getValue());
        $this->assertSame('3', $this->stats(['tipos_atendimento' => ['delivery', 'retirada']])['Pedidos']->getValue());
    }

    public function test_pedido_excluido_nao_entra(): void
    {
        $this->entregue();
        $this->entregue()->delete();

        $this->assertSame('1', $this->stats()['Pedidos']->getValue());
    }
}
