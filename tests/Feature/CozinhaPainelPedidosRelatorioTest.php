<?php

namespace Tests\Feature;

use App\Enums\Cozinha\AcaoProducao;
use App\Enums\Cozinha\MotivoRefacao;
use App\Enums\Cozinha\PrioridadeProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\StatusPedidoEnum;
use App\Filament\Pages\PainelPedidos;
use App\Filament\Pages\RelatorioProducao;
use App\Filament\Widgets\CozinhaAgoraWidget;
use App\Filament\Widgets\ProducaoRankingsWidget;
use App\Filament\Widgets\ProducaoStatsOverview;
use App\Filament\Widgets\TempoMedioProducaoWidget;
use App\Models\FichaTecnica;
use App\Models\Pedido;
use App\Models\ProducaoEvento;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Models\User;
use App\Services\Cozinha\ProducaoService;
use App\Services\Cozinha\RelatorioProducaoService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** Fase 3: produção no Painel de Pedidos, relatório e widgets. */
class CozinhaPainelPedidosRelatorioTest extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    private SetorProducao $pizzaria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow('2026-10-14 20:00:00');
        $this->pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria', 'tempo_alvo_min' => 15]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function gerente(): User
    {
        $user = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:pedido', 'guard_name' => 'web']));

        return $user;
    }

    /** Pedido de mesa com 2 pizzas na fila da Pizzaria, a 1ª já em preparo (pedido PREPARANDO). */
    private function pedidoEmPreparo(): Pedido
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas), 2);
        $this->abrir($pedido);
        app(ProducaoService::class)->iniciar(ProducaoItem::orderBy('id')->first(), $this->operador());

        return $pedido->refresh();
    }

    public function test_card_mostra_o_andamento_da_cozinha(): void
    {
        $this->actingAs($this->gerente());
        $pedido = $this->pedidoEmPreparo();
        app(ProducaoService::class)->concluir(ProducaoItem::orderBy('id')->first());

        Livewire::test(PainelPedidos::class)
            ->assertSee('Cozinha: Em produção')
            ->assertSee('1/2 prontos')
            ->assertSee('Ver produção');

        $this->assertSame(StatusPedidoEnum::PREPARANDO, $pedido->fresh()->status());
    }

    public function test_marcar_pronto_com_itens_na_cozinha_pede_confirmacao_e_forca_os_itens(): void
    {
        $this->actingAs($this->gerente());
        $pedido = $this->pedidoEmPreparo();

        Livewire::test(PainelPedidos::class)
            ->mountAction('avancar', ['pedido' => $pedido->id])
            ->assertActionMounted('avancar')
            ->assertMountedActionModalSee('2 item(ns) do pedido')
            ->callMountedAction();

        $this->assertSame(StatusPedidoEnum::PRONTO, $pedido->fresh()->status());
        $this->assertSame(0, app(ProducaoService::class)->pendentesDoPedido($pedido));
        $this->assertSame(2, ProducaoEvento::where('acao', AcaoProducao::ForcadoPeloPainel)->count());
    }

    public function test_sem_permissao_de_forcar_o_pedido_nao_avanca_antes_da_cozinha(): void
    {
        Role::findByName('Gerente')->revokePermissionTo('forcar:producao_item');
        $this->actingAs($this->gerente());
        $pedido = $this->pedidoEmPreparo();

        Livewire::test(PainelPedidos::class)
            ->callAction('avancar', arguments: ['pedido' => $pedido->id])
            ->assertNotified('A cozinha ainda não terminou este pedido');

        $this->assertSame(StatusPedidoEnum::PREPARANDO, $pedido->fresh()->status());
    }

    public function test_aceitar_pedido_aberto_nao_pede_confirmacao_de_forcar(): void
    {
        $this->actingAs($this->gerente());
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas));
        $this->abrir($pedido);

        Livewire::test(PainelPedidos::class)
            ->mountAction('avancar', ['pedido' => $pedido->id])
            ->assertActionNotMounted('avancar');

        $this->assertSame(StatusPedidoEnum::PREPARANDO, $pedido->fresh()->status());
    }

    public function test_priorizar_liberar_e_ver_producao_pelo_painel(): void
    {
        $this->actingAs($this->gerente());
        $pedido = $this->pedidoEmPreparo();
        $segurado = ProducaoItem::factory()->doSetor($this->pizzaria)->doItem($pedido->item_pedido_pedido_id->first())
            ->status(StatusItemProducao::Aguardando)
            ->create(['unidade_seq' => 9, 'liberar_em' => now()->addMinutes(8)]);

        Livewire::test(PainelPedidos::class)
            ->assertSee('Liberar produção agora')
            ->callAction('priorizar', ['prioridade' => PrioridadeProducao::Urgente->value], ['pedido' => $pedido->id])
            ->callAction('liberarProducao', arguments: ['pedido' => $pedido->id])
            ->assertNotified('1 item(ns) liberado(s) para a cozinha')
            ->mountAction('verProducao', ['pedido' => $pedido->id])
            ->assertMountedActionModalSee(['Iniciado', 'Priorizado', 'Liberado']);

        $this->assertSame(PrioridadeProducao::Urgente, $pedido->fresh()->pedido_prioridade_producao);
        $this->assertSame(StatusItemProducao::NaFila, $segurado->fresh()->status);
        $this->assertSame(PrioridadeProducao::Urgente, $segurado->fresh()->prioridade);
    }

    public function test_garcom_nao_ve_as_acoes_de_producao(): void
    {
        $garcom = User::factory()->garcom()->create(['name_first' => 'Beto']);
        $garcom->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:pedido', 'guard_name' => 'web']));
        $this->actingAs($garcom);
        $pedido = $this->pedidoEmPreparo();

        Livewire::test(PainelPedidos::class)
            ->assertDontSee('Ver produção')
            ->assertDontSee('Priorizar na cozinha')
            ->assertActionHidden('priorizar', ['pedido' => $pedido->id])
            ->assertActionHidden('verProducao', ['pedido' => $pedido->id]);
    }

    public function test_relatorio_calcula_tempos_prazo_operadores_refacoes_e_cobertura(): void
    {
        $this->actingAs($this->gerente());
        $pizzas = $this->categoria('Pizzas');
        $calabresa = $this->produto('Calabresa', $pizzas);
        $portuguesa = $this->produto('Portuguesa', $pizzas);
        FichaTecnica::factory()->doProduto($calabresa)->create();
        $pedido = $this->pedidoMesa();
        $this->abrir($pedido);
        $itemCalabresa = $this->item($pedido, $calabresa, 3);
        $itemPortuguesa = $this->item($pedido, $portuguesa, 1);
        $ana = User::factory()->cozinha()->create(['name_first' => 'Ana']);

        $concluido = fn (int $fila, int $preparo, array $extras = []) => ProducaoItem::factory()->doSetor($this->pizzaria)->doItem($itemCalabresa)
            ->status(StatusItemProducao::Pronto)
            ->create($extras + [
                'unidade_seq' => fake()->unique()->numberBetween(1, 999),
                'liberado_em' => now()->subMinutes($fila + $preparo),
                'iniciado_em' => now()->subMinutes($preparo),
                'concluido_em' => now(),
                'operador_id' => $ana->id,
                'operador_nome' => 'Ana',
            ]);

        $concluido(4, 8);    // 12 min: no prazo (alvo 15)
        $concluido(6, 14);   // 20 min: fora do prazo
        $original = $concluido(2, 4, ['status' => StatusItemProducao::Cancelado, 'motivo_refacao' => MotivoRefacao::Queimou]);
        $concluido(1, 3, ['geracao' => 2, 'refeito_de_id' => $original->id, 'motivo_refacao' => MotivoRefacao::Queimou]);
        $forcado = $concluido(0, 0, ['operador_id' => null, 'operador_nome' => null]);
        ProducaoEvento::create(['producao_item_id' => $forcado->id, 'pedido_id' => $pedido->id, 'setor_producao_id' => $this->pizzaria->id, 'acao' => AcaoProducao::ForcadoPeloPainel]);

        $servico = app(RelatorioProducaoService::class);
        $resumo = $servico->resumo(now()->startOfDay(), now());

        $this->assertSame(3, $resumo['concluidos'], 'Cancelado e forçado ficam de fora.');
        $this->assertEquals(round((12 + 20 + 4) * 60 / 3, 1), $resumo['total_seg']);
        $this->assertEquals(round((8 + 14 + 3) * 60 / 3, 1), $resumo['preparo_seg']);
        $this->assertEquals(66.7, $resumo['no_prazo_pct']);
        $this->assertSame(1, $resumo['refeitos']);
        $this->assertSame(3.0, $resumo['itens_hora']);

        $this->assertSame([['Ana', 3]], $servico->operadores(now()->startOfDay(), now())->map(fn ($l) => [$l->operador, (int) $l->itens])->all());
        $this->assertSame(['Queimou'], $servico->refacoesPorMotivo(now()->startOfDay(), now())->pluck('rotulo')->all());
        $this->assertSame(0, $servico->resumo(now()->startOfDay(), now(), ['setor_id' => SetorProducao::factory()->create()->id])['concluidos']);

        $cobertura = $servico->coberturaFichas(now()->startOfDay(), now());
        $this->assertEquals(75.0, $cobertura['percentual']);
        $this->assertSame(['Portuguesa'], $cobertura['sem_ficha']->pluck('produto')->all());
        $this->assertSame($itemPortuguesa->item_pedido_produto_id, (int) $cobertura['sem_ficha']->first()->produto_id);
    }

    public function test_relatorio_abre_para_o_gerente_exporta_csv_e_fica_fechado_para_o_garcom(): void
    {
        $this->actingAs($this->gerente());

        Livewire::test(RelatorioProducao::class)
            ->assertSuccessful()
            ->callAction('exportarCsv')
            ->assertFileDownloaded('producao-20261008-20261014.csv');

        // Widgets do dashboard são lazy: testados direto.
        Livewire::test(CozinhaAgoraWidget::class)->assertSee(['Cozinha agora', 'Pizzaria', 'Sem tablet']);
        Livewire::test(ProducaoStatsOverview::class, ['pageFilters' => ['inicio' => '2026-10-08', 'fim' => '2026-10-14']])->assertSee('Tempo total médio');
        Livewire::test(ProducaoRankingsWidget::class, ['pageFilters' => []])->assertSee('Produtos mais lentos');
        Livewire::test(TempoMedioProducaoWidget::class)->assertSee('Tempo total hoje');

        $this->actingAs(User::factory()->garcom()->create(['name_first' => 'Beto']));
        $this->get(RelatorioProducao::getUrl())->assertForbidden();
    }
}
