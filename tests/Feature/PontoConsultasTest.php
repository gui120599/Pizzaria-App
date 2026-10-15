<?php

namespace Tests\Feature;

use App\Enums\Ponto\OrigemMarcacao;
use App\Enums\Ponto\StatusPeriodo;
use App\Enums\Ponto\StatusRepasse;
use App\Filament\Garcom\Pages\MeuEspelho;
use App\Filament\Garcom\Pages\MeusRepasses;
use App\Filament\Pages\EspelhoPonto;
use App\Filament\Pages\RelatorioCorrecoesPonto;
use App\Filament\Resources\Adiantamentos\Pages\ListAdiantamentos;
use App\Filament\Resources\Colaboradores\Pages\EditColaborador;
use App\Filament\Resources\Colaboradores\Pages\ListColaboradores;
use App\Filament\Resources\Colaboradores\RelationManagers\AdiantamentosRelationManager;
use App\Filament\Resources\Colaboradores\RelationManagers\EscalasRelationManager;
use App\Filament\Resources\Colaboradores\RelationManagers\RemuneracoesRelationManager;
use App\Filament\Resources\ContasFinanceiras\Pages\ListContasFinanceiras;
use App\Filament\Resources\Escalas\Pages\ListEscalas;
use App\Filament\Resources\Feriados\Pages\ManageFeriados;
use App\Filament\Resources\MovimentacoesFinanceiras\Pages\ListMovimentacoesFinanceiras;
use App\Filament\Resources\PontoAjustes\Pages\ListPontoAjustes;
use App\Filament\Resources\PontoPeriodos\Pages\EditPontoPeriodo;
use App\Filament\Resources\PontoPeriodos\Pages\ListPontoPeriodos;
use App\Filament\Resources\PontoPeriodos\RelationManagers\RepassesRelationManager;
use App\Filament\Resources\Repasses\Pages\ListRepasses;
use App\Filament\Resources\Repasses\Pages\ViewRepasse;
use App\Filament\Widgets\CustoPessoalPeriodoWidget;
use App\Filament\Widgets\InconsistenciasPendentesWidget;
use App\Filament\Widgets\SaldosContasWidget;
use App\Filament\Widgets\TrabalhandoAgoraWidget;
use App\Models\Adiantamento;
use App\Models\Colaborador;
use App\Models\ContaFinanceira;
use App\Models\Escala;
use App\Models\Feriado;
use App\Models\MovimentacaoFinanceira;
use App\Models\PontoAjuste;
use App\Models\PontoDia;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use App\Models\RepasseItem;
use App\Models\User;
use App\Services\Ponto\PontoService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Telas do ponto e das contas financeiras sem N+1: com lazy loading
 * proibido, qualquer relação carregada linha a linha numa lista estoura.
 */
class PontoConsultasTest extends TestCase
{
    use RefreshDatabase;

    private PontoPeriodo $periodo;

    /** @var Collection<int, Colaborador> */
    private $colaboradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 19:00:00');
        $this->criarCenario();
        Model::preventLazyLoading();
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    public function test_listas_e_paginas_do_admin_sem_lazy_loading(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $colaborador = $this->colaboradores->first();
        $repasse = Repasse::query()->first();

        Livewire::test(ListColaboradores::class)->assertOk()->assertCanSeeTableRecords($this->colaboradores);
        Livewire::test(ListEscalas::class)->assertOk();
        Livewire::test(ManageFeriados::class)->assertOk();
        Livewire::test(ListPontoAjustes::class)->assertOk();
        Livewire::test(ListAdiantamentos::class)->assertOk();
        Livewire::test(ListPontoPeriodos::class)->assertOk();
        Livewire::test(ListRepasses::class)->assertOk();
        Livewire::test(ListContasFinanceiras::class)->assertOk();
        Livewire::test(ListMovimentacoesFinanceiras::class)->assertOk();
        $espelho = Livewire::test(EspelhoPonto::class, ['periodo' => $this->periodo->id])
            ->assertOk()
            ->call('mountAction', 'dia', ['colaborador' => $colaborador->id, 'dia' => '2026-10-12'])
            ->assertActionMounted('dia');
        $this->assertStringContainsString('Correções', $espelho->instance()->getMountedAction()->getModalContent()->render());
        Livewire::test(RelatorioCorrecoesPonto::class)->assertOk();
        Livewire::test(ViewRepasse::class, ['record' => $repasse->id])->assertOk();
        Livewire::test(EditPontoPeriodo::class, ['record' => $this->periodo->id])->assertOk();
        Livewire::test(RepassesRelationManager::class, ['ownerRecord' => $this->periodo, 'pageClass' => EditPontoPeriodo::class])->assertOk();

        foreach ([RemuneracoesRelationManager::class, EscalasRelationManager::class, AdiantamentosRelationManager::class] as $relationManager) {
            Livewire::test($relationManager, ['ownerRecord' => $colaborador, 'pageClass' => EditColaborador::class])->assertOk();
        }

        $this->get(route('ponto.espelho.imprimir', ['periodo' => $this->periodo->id]))->assertOk()->assertSee($colaborador->nome);
        $this->get(route('ponto.repasses.recibo', $repasse))->assertOk();

        foreach ([TrabalhandoAgoraWidget::class, InconsistenciasPendentesWidget::class, CustoPessoalPeriodoWidget::class, SaldosContasWidget::class] as $widget) {
            Livewire::test($widget)->assertOk();
        }
    }

    public function test_telas_do_colaborador_sem_lazy_loading(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
        $this->actingAs($this->colaboradores->first()->user);

        $espelho = Livewire::test(MeuEspelho::class)->assertOk()->call('mountAction', 'dia', ['dia' => '2026-10-12']);
        $this->assertStringContainsString('Correções do gestor', $espelho->instance()->getMountedAction()->getModalContent()->render());

        $repasses = Livewire::test(MeusRepasses::class)->assertOk()->call('mountAction', 'itens', ['repasse' => Repasse::query()->where('colaborador_id', $this->colaboradores->first()->id)->value('id')]);
        $this->assertStringContainsString('Líquido', $repasses->instance()->getMountedAction()->getModalContent()->render());
    }

    private function criarCenario(): void
    {
        $escala = Escala::factory()->noitePizzaria()->create();
        $this->colaboradores = Colaborador::factory()->count(3)->comUsuario()->comEscala($escala)->comRemuneracao()->create();
        $this->periodo = PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->status(StatusPeriodo::EmConferencia)->create();
        $conta = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();
        Feriado::factory()->count(2)->create();

        foreach ($this->colaboradores as $colaborador) {
            app(PontoService::class)->registrarMarcacao($colaborador, OrigemMarcacao::App);
            PontoDia::factory()->create(['colaborador_id' => $colaborador->id, 'ponto_periodo_id' => $this->periodo->id, 'data_referencia' => '2026-10-08']);
            PontoAjuste::factory()->inclusao('2026-10-13 18:00:00')->create(['colaborador_id' => $colaborador->id, 'data_referencia' => '2026-10-13']);
            PontoAjuste::factory()->doGestor()->inclusao('2026-10-12 18:00:00')->create(['colaborador_id' => $colaborador->id, 'data_referencia' => '2026-10-12']);
            Adiantamento::factory()->create(['colaborador_id' => $colaborador->id, 'conta_financeira_id' => $conta->id]);
            $repasse = Repasse::factory()->status(StatusRepasse::Aprovado)->create(['ponto_periodo_id' => $this->periodo->id, 'colaborador_id' => $colaborador->id, 'valor_liquido' => 300, 'aprovado_em' => now()]);
            RepasseItem::factory()->count(2)->create(['repasse_id' => $repasse->id]);
        }

        MovimentacaoFinanceira::factory()->count(3)->create(['conta_financeira_id' => $conta->id]);
    }
}
