<?php

namespace Tests\Feature;

use App\Enums\Ponto\MotivoAjuste;
use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\TipoAjuste;
use App\Filament\Garcom\Pages\MeuEspelho;
use App\Filament\Pages\EspelhoPonto;
use App\Models\Colaborador;
use App\Models\PontoAjuste;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * As correções e os pedidos abrem por cima do modal do dia (ação aninhada).
 * O Filament só resolve ação aninhada registrada no pai: sem isso o clique
 * é ignorado em silêncio, e chamar a ação direto (sem o "dia") não pega.
 */
class EspelhoPontoAcoesTest extends TestCase
{
    use RefreshDatabase;

    private const DIA = '2026-10-13';

    private Colaborador $colaborador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 18:00:00');
        $this->colaborador = Colaborador::factory()->comUsuario()->comEscala()->create();
    }

    public function test_gestor_inclui_marcacao_pelo_modal_do_dia(): void
    {
        $this->espelhoDoGerente()
            ->callAction([$this->dia(), TestAction::make('incluirMarcacao')->arguments($this->argumentos())], [
                'hora' => '18:05',
                'motivo' => MotivoAjuste::Esquecimento->value,
                'justificativa' => 'Esqueceu de bater a entrada.',
            ])
            ->assertHasNoErrors()
            ->assertNotified('Marcação incluída');

        $this->assertSame('2026-10-13 18:05', PontoAjuste::query()->sole()->data_hora->format('Y-m-d H:i'));
    }

    public function test_gestor_lanca_o_dia_completo_pelo_modal_do_dia(): void
    {
        $this->espelhoDoGerente()
            ->callAction([$this->dia(), TestAction::make('lancarDia')->arguments($this->argumentos())], [
                // Estado interno do repeater simples: cada item vira ['hora' => …].
                'horarios' => [['hora' => '17:30'], ['hora' => '21:00'], ['hora' => '22:00'], ['hora' => '23:50']],
                'motivo' => MotivoAjuste::FalhaSistema->value,
                'justificativa' => 'Celular quebrado, lançado pela escala.',
            ])
            ->assertHasNoErrors()
            ->assertNotified('Dia lançado');

        $this->assertSame(4, PontoAjuste::query()->where('tipo', TipoAjuste::Inclusao)->count());
    }

    public function test_colaborador_da_ciencia_e_pede_ajuste_pelo_modal_do_dia(): void
    {
        $correcao = PontoAjuste::factory()->doGestor()->inclusao('2026-10-13 18:00:00')
            ->create(['colaborador_id' => $this->colaborador->id, 'data_referencia' => self::DIA]);
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
        $this->actingAs($this->colaborador->user);
        $dia = TestAction::make('dia')->arguments(['dia' => self::DIA]);

        Livewire::test(MeuEspelho::class)
            ->callAction([$dia, TestAction::make('ciente')->arguments(['dia' => self::DIA])])
            ->assertNotified('Ciência registrada');

        Livewire::test(MeuEspelho::class)
            ->callAction([$dia, TestAction::make('solicitarAjuste')->arguments(['dia' => self::DIA, 'contestar' => true])], [
                'tipo' => TipoAjuste::Inclusao->value,
                'hora' => '20:10',
                'motivo' => MotivoAjuste::Esquecimento->value,
                'justificativa' => 'Saí para entrega e esqueci de marcar.',
            ])
            ->assertNotified('Pedido enviado ao gestor');

        $this->assertNotNull($correcao->fresh()->colaborador_ciente_em);
        $this->assertSame(StatusAjuste::Pendente, PontoAjuste::query()->where('origem', OrigemAjuste::Colaborador)->sole()->status);
    }

    private function espelhoDoGerente(): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));

        return Livewire::test(EspelhoPonto::class);
    }

    private function dia(): TestAction
    {
        return TestAction::make('dia')->arguments($this->argumentos());
    }

    /** @return array{colaborador: int, dia: string} */
    private function argumentos(): array
    {
        return ['colaborador' => $this->colaborador->id, 'dia' => self::DIA];
    }
}
