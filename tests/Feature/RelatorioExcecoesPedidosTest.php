<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\RelatorioCortesias;
use App\Filament\Pages\RelatorioExcecoesPedidos;
use App\Filament\Widgets\ExcecoesCancelamentosPorMotivoWidget;
use App\Filament\Widgets\ExcecoesCancelamentosPorUsuarioWidget;
use App\Filament\Widgets\ExcecoesEntreguesNaoPagosWidget;
use App\Filament\Widgets\ExcecoesItensRemovidosWidget;
use App\Filament\Widgets\ExcecoesLancamentosPorUsuarioWidget;
use App\Filament\Widgets\ExcecoesPedidosExcluidosWidget;
use App\Filament\Widgets\ExcecoesPedidosStatsOverview;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\RelatorioExcecoesPedidosService;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Relatório de Exceções e Perdas dos pedidos. Um cenário de outubro de 2026
 * com os números conferidos à mão; cada indicador usa a regra do seu evento.
 */
class RelatorioExcecoesPedidosTest extends TestCase
{
    use RefreshDatabase;

    private const FILTROS = ['inicio' => '2026-10-01', 'fim' => '2026-10-31'];

    private User $ana;

    private User $beto;

    private User $carla;

    private Produto $produto;

    /** @var array<string, Pedido> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->ana = User::factory()->create(['name' => 'Ana Atendente', 'name_first' => 'Ana']);
        $this->beto = User::factory()->create(['name' => 'Beto Gerente', 'name_first' => 'Beto']);
        $this->carla = User::factory()->create(['name' => 'Carla Caixa', 'name_first' => 'Carla']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);

        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
    }

    /** @param array<string, mixed> $extra */
    private function pedido(string $status, array $extra = []): Pedido
    {
        $total = $extra['pedido_valor_total'] ?? 20.0;

        return Pedido::create($extra + [
            'pedido_status' => $status,
            'pedido_valor_total' => $total,
            'pedido_valor_itens' => $total,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function item(Pedido $pedido, array $extra = [], ?string $alteradoEm = null): ItensPedido
    {
        $item = ItensPedido::create($extra + [
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 10.0,
            'item_pedido_valor' => 10.0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        if ($alteradoEm !== null) {
            $item->forceFill(['updated_at' => $alteradoEm])->saveQuietly();
        }

        return $item;
    }

    private function excluir(Pedido $pedido, string $quando): void
    {
        $pedido->delete();
        Pedido::withTrashed()->whereKey($pedido->id)->update(['deleted_at' => $quando]);
    }

    private function relatorio(string $inicio = '2026-10-01', string $fim = '2026-10-31'): RelatorioExcecoesPedidosService
    {
        return new RelatorioExcecoesPedidosService(Carbon::parse($inicio), Carbon::parse($fim));
    }

    /**
     * Outubro de 2026. Rascunho, excluídos e pedidos de fora do período
     * existem de propósito, para provar o que fica de fora.
     */
    private function cenario(): void
    {
        $this->p['finalizado'] = $this->pedido('FINALIZADO', [
            'pedido_datahora_abertura' => '2026-10-05 12:00:00', 'pedido_usuario_garcom_id' => $this->ana->id,
            'pedido_valor_total' => 100, 'pedido_valor_itens' => 110, 'pedido_valor_desconto' => 10,
        ]);
        $this->p['entregue'] = $this->pedido('ENTREGUE', [
            'pedido_datahora_abertura' => '2026-10-06 12:00:00', 'pedido_usuario_garcom_id' => $this->ana->id,
            'pedido_valor_total' => 50,
        ]);
        $this->p['cancelado_cliente'] = $this->pedido('CANCELADO', [
            'pedido_datahora_abertura' => '2026-10-07 12:00:00', 'pedido_datahora_cancelado' => '2026-10-07 13:00:00',
            'pedido_usuario_garcom_id' => $this->ana->id, 'pedido_usuario_cancelou_id' => $this->beto->id,
            'pedido_motivo_cancelamento' => MotivoCancelamentoEnum::CLIENTE_DESISTIU->value,
            'pedido_valor_total' => 80, 'pedido_valor_itens' => 100, 'pedido_valor_desconto' => 20,
        ]);
        $this->p['cancelado_outro'] = $this->pedido('CANCELADO', [
            'pedido_datahora_abertura' => '2026-10-08 12:00:00', 'pedido_datahora_cancelado' => '2026-10-08 12:30:00',
            'pedido_usuario_garcom_id' => $this->ana->id, 'pedido_usuario_cancelou_id' => $this->ana->id,
            'pedido_motivo_cancelamento' => MotivoCancelamentoEnum::OUTRO->value,
            'pedido_motivo_cancelamento_observacao' => 'Cliente ligou',
            'pedido_valor_total' => 40,
        ]);
        // Aberto em setembro, mas cancelado em outubro: conta em outubro.
        $this->p['cancelado_de_setembro'] = $this->pedido('CANCELADO', [
            'pedido_datahora_abertura' => '2026-09-28 12:00:00', 'pedido_datahora_cancelado' => '2026-10-02 10:00:00',
            'pedido_usuario_cancelou_id' => $this->beto->id,
            'pedido_motivo_cancelamento' => MotivoCancelamentoEnum::CLIENTE_DESISTIU->value,
            'pedido_valor_total' => 60,
        ]);
        // Aberto em outubro, cancelado em novembro: fora.
        $this->p['cancelado_depois'] = $this->pedido('CANCELADO', [
            'pedido_datahora_abertura' => '2026-10-10 12:00:00', 'pedido_datahora_cancelado' => '2026-11-02 10:00:00',
            'pedido_usuario_garcom_id' => $this->carla->id, 'pedido_usuario_cancelou_id' => $this->beto->id,
            'pedido_motivo_cancelamento' => MotivoCancelamentoEnum::DEMORA->value,
            'pedido_valor_total' => 70,
        ]);
        $this->p['rascunho'] = $this->pedido('INICIADO', [
            'pedido_datahora_abertura' => '2026-10-09 12:00:00', 'pedido_usuario_garcom_id' => $this->ana->id,
            'pedido_valor_total' => 30,
        ]);
        // Pedido antigo cancelado sem a data do cancelamento: entra pela abertura.
        $this->p['cancelado_sem_data'] = $this->pedido('CANCELADO', [
            'pedido_datahora_abertura' => '2026-10-12 12:00:00', 'pedido_usuario_garcom_id' => $this->ana->id,
            'pedido_valor_total' => 25,
        ]);
        $this->p['excluido'] = $this->pedido('FINALIZADO', [
            'pedido_datahora_abertura' => '2026-10-14 12:00:00', 'pedido_motivo_exclusao' => 'duplicado',
            'pedido_exclusao_observacao' => 'Lançado duas vezes', 'pedido_usuario_excluiu_id' => $this->beto->id,
            'pedido_valor_total' => 90,
        ]);
        $this->excluir($this->p['excluido'], '2026-10-15 10:00:00');
        $this->p['excluido_depois'] = $this->pedido('FINALIZADO', ['pedido_datahora_abertura' => '2026-10-14 13:00:00']);
        $this->excluir($this->p['excluido_depois'], '2026-11-05 10:00:00');
        $this->p['entregue_de_setembro'] = $this->pedido('ENTREGUE', [
            'pedido_datahora_abertura' => '2026-09-15 12:00:00', 'pedido_valor_total' => 33,
        ]);
        $this->p['entregue_pago'] = $this->pedido('ENTREGUE', [
            'pedido_datahora_abertura' => '2026-10-20 12:00:00', 'pedido_valor_total' => 44,
            'pedido_venda_id' => Venda::create(['venda_status' => 'INICIADA'])->id,
        ]);

        $this->item($this->p['finalizado'], ['item_pedido_status' => 'REMOVIDO', 'item_pedido_valor' => 15, 'item_pedido_usuario_removeu' => $this->beto->id], '2026-10-05 12:10:00');
        $this->item($this->p['entregue'], ['item_pedido_status' => 'REMOVIDO', 'item_pedido_valor' => 10, 'item_pedido_usuario_removeu' => $this->ana->id], '2026-10-06 12:10:00');
        $this->item($this->p['finalizado'], ['item_pedido_status' => 'REMOVIDO', 'item_pedido_valor' => 5], '2026-10-05 12:20:00');
        // Fora: rascunho, pedido excluído, alteração anterior ao período e item que segue no pedido.
        $this->item($this->p['rascunho'], ['item_pedido_status' => 'REMOVIDO', 'item_pedido_valor' => 99, 'item_pedido_usuario_removeu' => $this->ana->id], '2026-10-09 12:10:00');
        $this->item($this->p['excluido'], ['item_pedido_status' => 'REMOVIDO', 'item_pedido_valor' => 88, 'item_pedido_usuario_removeu' => $this->ana->id], '2026-10-14 12:10:00');
        $this->item($this->p['finalizado'], ['item_pedido_status' => 'REMOVIDO', 'item_pedido_valor' => 77, 'item_pedido_usuario_removeu' => $this->ana->id], '2026-09-20 12:10:00');
        $this->item($this->p['finalizado']);

        $this->item($this->p['finalizado'], [
            'item_pedido_cortesia' => true, 'item_pedido_cortesia_motivo' => 'Aniversário',
            'item_pedido_cortesia_em' => '2026-10-05 12:30:00', 'item_pedido_desconto' => 30, 'item_pedido_valor' => 0,
        ]);
    }

    public function test_resumo_conta_cada_indicador_pela_regra_do_seu_evento(): void
    {
        $this->cenario();

        $resumo = $this->relatorio()->resumo();

        // Cancelados em outubro: dois de cliente, um "outro" e o sem data. O de novembro fica fora.
        $this->assertSame(4, $resumo['cancelados']['quantidade']);
        $this->assertSame(205.0, $resumo['cancelados']['valor']);
        // Pedidos reais abertos em outubro (cancelados inclusive): 100+50+80+40+70+25+44.
        $this->assertSame(409.0, $resumo['cancelados']['base']);
        $this->assertEqualsWithDelta(205 / 409 * 100, $resumo['cancelados']['percentual'], 0.001);

        $this->assertSame(3, $resumo['itens_removidos']['quantidade']);
        $this->assertSame(30.0, $resumo['itens_removidos']['valor']);

        // Só o desconto de pedido não cancelado: o de R$ 20 do cancelado não foi concedido.
        $this->assertSame(1, $resumo['descontos']['pedidos']);
        $this->assertSame(10.0, $resumo['descontos']['valor']);
        $this->assertEqualsWithDelta(10 / 204 * 100, $resumo['descontos']['percentual'], 0.001);

        $this->assertSame(1, $resumo['cortesias']['itens']);
        $this->assertSame(30.0, $resumo['cortesias']['valor']);

        $this->assertSame(1, $resumo['excluidos']['quantidade']);
        $this->assertSame(90.0, $resumo['excluidos']['valor']);

        // Entregue sem venda aberto em outubro; o de setembro e o já pago ficam fora.
        $this->assertSame(1, $resumo['nao_pagos']['quantidade']);
        $this->assertSame(50.0, $resumo['nao_pagos']['valor']);
    }

    public function test_periodo_sem_ocorrencias_zera_tudo_sem_dividir_por_zero(): void
    {
        $this->cenario();

        $resumo = $this->relatorio('2025-01-01', '2025-01-31')->resumo();

        $this->assertSame(0, $resumo['cancelados']['quantidade']);
        $this->assertSame(0.0, $resumo['cancelados']['percentual']);
        $this->assertSame(0.0, $resumo['descontos']['percentual']);
        $this->assertSame(0, $resumo['nao_pagos']['quantidade']);
    }

    public function test_cancelamentos_por_motivo_trazem_valor_percentual_e_observacao(): void
    {
        $this->cenario();

        $motivos = $this->relatorio()->cancelamentosPorMotivo();

        $this->assertSame('cliente_desistiu', $motivos->keys()->first());
        $this->assertSame(2, $motivos['cliente_desistiu']['quantidade']);
        $this->assertSame(140.0, $motivos['cliente_desistiu']['valor']);
        $this->assertSame('Cliente desistiu', $motivos['cliente_desistiu']['motivo']);
        $this->assertEqualsWithDelta(140 / 205 * 100, $motivos['cliente_desistiu']['percentual'], 0.001);

        $this->assertSame(1, $motivos['outro']['quantidade']);
        $this->assertSame(["#{$this->p['cancelado_outro']->id}: Cliente ligou"], $motivos['outro']['observacoes']);

        $this->assertSame('Não informado', $motivos['sem']['motivo']);
        $this->assertSame(25.0, $motivos['sem']['valor']);
        $this->assertArrayNotHasKey('demora', $motivos->all());
    }

    public function test_cancelamentos_por_usuario_cruzam_quem_lancou_e_quem_cancelou_em_ordem_alfabetica(): void
    {
        $this->cenario();

        $linhas = $this->relatorio()->cancelamentosPorUsuario()->values();

        $this->assertSame(
            [
                ['Ana Atendente', 'Ana Atendente', 1, 40.0],
                ['Ana Atendente', 'Beto Gerente', 1, 80.0],
                ['Ana Atendente', 'Não informado', 1, 25.0],
                ['Sem atendente (cardápio)', 'Beto Gerente', 1, 60.0],
            ],
            $linhas->map(fn (array $l): array => [$l['lancou'], $l['cancelou'], $l['quantidade'], $l['valor']])->all(),
        );
    }

    public function test_itens_removidos_por_usuario_deixam_o_autor_desconhecido_por_ultimo(): void
    {
        $this->cenario();

        $linhas = $this->relatorio()->itensRemovidosPorUsuario()->values();

        $this->assertSame(
            [['Ana Atendente', 1, 10.0], ['Beto Gerente', 1, 15.0], ['Não informado', 1, 5.0]],
            $linhas->map(fn (array $l): array => [$l['usuario'], $l['itens'], $l['valor']])->all(),
        );
    }

    public function test_lancamentos_por_usuario_somam_volume_e_excecoes_sem_ranking(): void
    {
        $this->cenario();

        $linhas = $this->relatorio()->lancamentosPorUsuario()->values();

        $this->assertSame(
            [
                // pedidos, valor (sem cancelados), ticket médio, cancelados dos lançados, itens removidos por ele
                ['Ana Atendente', 5, 150.0, 75.0, 3, 1],
                ['Beto Gerente', 0, 0.0, null, 0, 1],
                ['Carla Caixa', 1, 0.0, null, 0, 0],
                ['Sem atendente (cardápio)', 1, 44.0, 44.0, 1, 0],
            ],
            $linhas->map(fn (array $l): array => [$l['usuario'], $l['pedidos'], $l['valor'], $l['ticket_medio'], $l['cancelados'], $l['itens_removidos']])->all(),
        );
    }

    public function test_so_admin_e_gerente_acessam_a_pagina_e_os_widgets(): void
    {
        $widgets = [
            ExcecoesPedidosStatsOverview::class, ExcecoesCancelamentosPorMotivoWidget::class,
            ExcecoesCancelamentosPorUsuarioWidget::class, ExcecoesItensRemovidosWidget::class,
            ExcecoesPedidosExcluidosWidget::class, ExcecoesEntreguesNaoPagosWidget::class,
            ExcecoesLancamentosPorUsuarioWidget::class,
        ];

        foreach (['admin' => true, 'gerente' => true, 'atendente' => false, 'caixa' => false] as $role => $esperado) {
            $this->actingAs(User::factory()->{$role}()->create(['name_first' => ucfirst($role)]));

            $this->assertSame($esperado, RelatorioExcecoesPedidos::canAccess(), "página para {$role}");
            foreach ($widgets as $widget) {
                $this->assertSame($esperado, $widget::canView(), "{$widget} para {$role}");
            }
        }
    }

    public function test_pagina_monta_com_o_filtro_de_periodo(): void
    {
        Livewire::test(RelatorioExcecoesPedidos::class)->assertOk();
    }

    /** @return array<string, Stat> */
    private function stats(): array
    {
        $widget = Livewire::test(ExcecoesPedidosStatsOverview::class, ['pageFilters' => self::FILTROS])->instance();

        return collect((new ReflectionMethod($widget, 'getStats'))->invoke($widget))
            ->keyBy(fn (Stat $stat) => (string) $stat->getLabel())
            ->all();
    }

    public function test_stats_mostram_valores_e_o_comparativo_com_o_periodo_anterior(): void
    {
        $this->cenario();
        // Período anterior (setembro): um cancelamento de R$ 100.
        $this->pedido('CANCELADO', [
            'pedido_datahora_abertura' => '2026-09-10 12:00:00', 'pedido_datahora_cancelado' => '2026-09-10 13:00:00',
            'pedido_valor_total' => 100,
        ]);

        $stats = $this->stats();

        $this->assertSame('R$ 205,00', $stats['Pedidos cancelados']->getValue());
        $this->assertStringContainsString('4 pedidos', (string) $stats['Pedidos cancelados']->getDescription());
        $this->assertStringContainsString('▲ 105,0% vs. período anterior', (string) $stats['Pedidos cancelados']->getDescription());

        $this->assertSame('3', $stats['Itens removidos']->getValue());
        $this->assertSame('R$ 10,00', $stats['Descontos e cupons']->getValue());
        $this->assertSame('R$ 30,00', $stats['Cortesias']->getValue());
        $this->assertSame(RelatorioCortesias::getUrl(), $stats['Cortesias']->getUrl());
        $this->assertSame('1', $stats['Pedidos excluídos']->getValue());
        $this->assertSame('1', $stats['Entregues sem pagamento']->getValue());
    }

    public function test_comparativo_sem_base_mostra_travessao_e_sem_variacao_mostra_igual(): void
    {
        $this->cenario();

        $stats = $this->stats();

        // Nada excluído em setembro: sem base para comparar.
        $this->assertStringContainsString('— vs. período anterior', (string) $stats['Pedidos excluídos']->getDescription());
        // Um entregue sem pagamento em cada período.
        $this->assertStringContainsString('= 0,0% vs. período anterior', (string) $stats['Entregues sem pagamento']->getDescription());
    }

    public function test_tabela_de_lancamentos_nao_tem_nenhuma_coluna_de_tempo(): void
    {
        $this->cenario();

        $widget = Livewire::test(ExcecoesLancamentosPorUsuarioWidget::class, ['pageFilters' => self::FILTROS]);
        $colunas = $widget->instance()->getTable()->getColumns();

        $this->assertNotEmpty($colunas);
        foreach ($colunas as $nome => $coluna) {
            $this->assertDoesNotMatchRegularExpression('/tempo|minut|dura[cç]|hora|demora|prazo/i', $nome.' '.$coluna->getLabel());
        }

        $widget->assertSeeInOrder(['Ana Atendente', 'Beto Gerente', 'Carla Caixa', 'Sem atendente (cardápio)'])
            ->assertSee('Não reflete desempenho individual sem o contexto da operação');
    }

    public function test_tabelas_de_cancelamento_e_remocao_mostram_os_dados_do_periodo(): void
    {
        $this->cenario();

        Livewire::test(ExcecoesCancelamentosPorMotivoWidget::class, ['pageFilters' => self::FILTROS])
            ->assertSee('Cliente desistiu')
            ->assertSee('Cliente ligou')
            ->assertDontSee('Demora no atendimento');

        Livewire::test(ExcecoesCancelamentosPorUsuarioWidget::class, ['pageFilters' => self::FILTROS])
            ->assertSee('Ana Atendente')
            ->assertSee('Sem atendente (cardápio)');

        Livewire::test(ExcecoesItensRemovidosWidget::class, ['pageFilters' => self::FILTROS])
            ->assertSee('Beto Gerente')
            ->assertSee('Não informado');
    }

    public function test_excluidos_listam_so_os_do_periodo_com_motivo_e_autor(): void
    {
        $this->cenario();

        Livewire::test(ExcecoesPedidosExcluidosWidget::class, ['pageFilters' => self::FILTROS])
            ->assertCanSeeTableRecords([$this->p['excluido']])
            ->assertCanNotSeeTableRecords([$this->p['excluido_depois'], $this->p['finalizado']])
            ->assertTableColumnFormattedStateSet('pedido_motivo_exclusao', 'Pedido duplicado', $this->p['excluido'])
            ->assertTableColumnStateSet('usuarioExcluiu.name', 'Beto Gerente', $this->p['excluido'])
            ->assertTableColumnStateSet('pedido_exclusao_observacao', 'Lançado duas vezes', $this->p['excluido']);
    }

    public function test_entregues_nao_pagos_permitem_cancelar_o_pedido_entregue(): void
    {
        $this->cenario();

        Livewire::test(ExcecoesEntreguesNaoPagosWidget::class, ['pageFilters' => self::FILTROS])
            ->assertCanSeeTableRecords([$this->p['entregue']])
            ->assertCanNotSeeTableRecords([$this->p['entregue_de_setembro'], $this->p['entregue_pago'], $this->p['finalizado']])
            ->callAction(TestAction::make('cancelarPedidoEntregue')->table($this->p['entregue']), ['motivo' => MotivoCancelamentoEnum::PROBLEMA_PAGAMENTO->value])
            ->assertHasNoActionErrors();

        $this->assertSame('CANCELADO', $this->p['entregue']->fresh()->pedido_status);
        $this->assertSame(0, $this->relatorio()->entreguesNaoPagos()->count());
    }
}
