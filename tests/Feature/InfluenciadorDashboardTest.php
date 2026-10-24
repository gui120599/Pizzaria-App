<?php

namespace Tests\Feature;

use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Filament\Influenciador\Widgets\ComissaoStatsOverview;
use App\Filament\Influenciador\Widgets\PedidosPorDiaChart;
use App\Models\Cliente;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\Pedido;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke test do dashboard do parceiro: confirma que os widgets carregam sem
 * erro e que o escopo por influenciador funciona (não mistura dados de
 * outro influenciador nos números exibidos).
 */
class InfluenciadorDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function influenciadorAtivo(): Influenciador
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);

        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        return $influenciador;
    }

    public function test_stats_overview_widget_carrega_escopado_ao_influenciador_logado(): void
    {
        $meu = $this->influenciadorAtivo();
        $outro = $this->influenciadorAtivo();

        $cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_tipo' => 'PF']);

        $meuCupom = Cupom::create([
            'cupom_influenciador_id' => $meu->id,
            'cupom_codigo' => 'MEU'.uniqid(),
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);
        Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $meuCupom->id,
            'pedido_valor_total' => 100.0,
            'pedido_status' => 'FINALIZADO',
        ]);

        $cupomDoOutro = Cupom::create([
            'cupom_influenciador_id' => $outro->id,
            'cupom_codigo' => 'OUTRO'.uniqid(),
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);
        Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $cupomDoOutro->id,
            'pedido_valor_total' => 999.0,
            'pedido_status' => 'FINALIZADO',
        ]);

        $this->actingAs($meu, 'influenciador');
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        Livewire::test(ComissaoStatsOverview::class)
            ->assertOk()
            ->assertSee('Pedidos no Mês')
            ->assertSee('R$ 100,00')
            ->assertDontSee('R$ 999,00');
    }

    public function test_pedidos_por_dia_chart_carrega_sem_erro(): void
    {
        $this->actingAs($this->influenciadorAtivo(), 'influenciador');
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        Livewire::test(PedidosPorDiaChart::class)->assertOk();
    }

    public function test_dashboard_completo_carrega_via_http(): void
    {
        $this->actingAs($this->influenciadorAtivo(), 'influenciador');

        $this->get('/influenciadores')->assertOk();
    }
}
