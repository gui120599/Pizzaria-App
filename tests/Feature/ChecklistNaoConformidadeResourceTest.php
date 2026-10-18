<?php

namespace Tests\Feature;

use App\Enums\Checklist\NaoConformidadeStatus;
use App\Filament\Resources\ChecklistNaoConformidades\Pages\EditChecklistNaoConformidade;
use App\Filament\Resources\ChecklistNaoConformidades\Pages\ListChecklistNaoConformidades;
use App\Models\ChecklistNaoConformidade;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChecklistNaoConformidadeResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_registra_quem_resolveu_e_quando(): void
    {
        $this->travelTo('2026-10-13 10:00:00');
        $gerente = $this->gerente();
        $nc = ChecklistNaoConformidade::factory()->create();
        $this->actingAs($gerente);

        Livewire::test(EditChecklistNaoConformidade::class, ['record' => $nc->id])
            ->fillForm([
                'status' => NaoConformidadeStatus::Resolvida->value,
                'acao_corretiva' => 'Trocar a borracha da porta.',
                'observacao_resolucao' => 'Borracha trocada pelo técnico.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $nc->refresh();
        $this->assertSame(NaoConformidadeStatus::Resolvida, $nc->status);
        $this->assertSame($gerente->id, $nc->resolvida_por);
        $this->assertSame('2026-10-13 10:00:00', $nc->resolvida_em->format('Y-m-d H:i:s'));
    }

    public function test_resolver_sem_dizer_como_e_recusado(): void
    {
        $nc = ChecklistNaoConformidade::factory()->create();
        $this->actingAs($this->gerente());

        Livewire::test(EditChecklistNaoConformidade::class, ['record' => $nc->id])
            ->fillForm(['status' => NaoConformidadeStatus::Resolvida->value, 'observacao_resolucao' => null])
            ->call('save')
            ->assertHasFormErrors(['observacao_resolucao' => 'required']);

        $this->assertSame(NaoConformidadeStatus::Aberta, $nc->fresh()->status);
    }

    public function test_reabrir_limpa_o_registro_de_resolucao(): void
    {
        $nc = ChecklistNaoConformidade::factory()->resolvida()->create(['observacao_resolucao' => 'Feito.']);
        $this->actingAs($this->gerente());

        Livewire::test(EditChecklistNaoConformidade::class, ['record' => $nc->id])
            ->fillForm(['status' => NaoConformidadeStatus::EmAndamento->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $nc->refresh();
        $this->assertSame(NaoConformidadeStatus::EmAndamento, $nc->status);
        $this->assertNull($nc->resolvida_em);
    }

    public function test_lista_mostra_so_as_pendentes_por_padrao(): void
    {
        $aberta = ChecklistNaoConformidade::factory()->create();
        $resolvida = ChecklistNaoConformidade::factory()->resolvida()->create();
        $this->actingAs($this->gerente());

        Livewire::test(ListChecklistNaoConformidades::class)
            ->assertCanSeeTableRecords([$aberta])
            ->assertCanNotSeeTableRecords([$resolvida]);
    }

    public function test_cancelar_em_massa_cancela_so_as_pendentes_e_grava_motivo_e_autor(): void
    {
        $this->travelTo('2026-10-18 10:00:00');
        $gerente = $this->gerente();
        $aberta = ChecklistNaoConformidade::factory()->create();
        $emAndamento = ChecklistNaoConformidade::factory()->create(['status' => NaoConformidadeStatus::EmAndamento]);
        $resolvida = ChecklistNaoConformidade::factory()->resolvida()->create(['observacao_resolucao' => 'Resolvida de verdade.']);
        $this->actingAs($gerente);

        Livewire::test(ListChecklistNaoConformidades::class)
            ->filterTable('status', [])
            ->selectTableRecords([$aberta->id, $emAndamento->id, $resolvida->id])
            ->callAction(TestAction::make('cancelar')->table()->bulk(), [
                'observacao_resolucao' => 'Falso positivo: havia estoque.',
            ])
            ->assertNotified(Notification::make()->title('2 canceladas, 1 ignorada')->color('success'));

        foreach ([$aberta, $emAndamento] as $nc) {
            $nc->refresh();
            $this->assertSame(NaoConformidadeStatus::Cancelada, $nc->status);
            $this->assertSame('Falso positivo: havia estoque.', $nc->observacao_resolucao);
            $this->assertSame($gerente->id, $nc->resolvida_por);
            $this->assertNotNull($nc->resolvida_em);
        }
        $resolvida->refresh();
        $this->assertSame(NaoConformidadeStatus::Resolvida, $resolvida->status);
        $this->assertSame('Resolvida de verdade.', $resolvida->observacao_resolucao);
    }

    public function test_cancelar_em_massa_exige_o_motivo(): void
    {
        $nc = ChecklistNaoConformidade::factory()->create();
        $this->actingAs($this->gerente());

        Livewire::test(ListChecklistNaoConformidades::class)
            ->selectTableRecords([$nc->id])
            ->callAction(TestAction::make('cancelar')->table()->bulk(), ['observacao_resolucao' => ''])
            ->assertHasActionErrors(['observacao_resolucao' => 'required']);

        $this->assertSame(NaoConformidadeStatus::Aberta, $nc->fresh()->status);
    }

    public function test_cancelar_em_massa_ignora_registro_que_o_usuario_nao_pode_atualizar(): void
    {
        $nc = ChecklistNaoConformidade::factory()->create();
        $leitor = User::factory()->create(['name_first' => 'Leitor']);
        $leitor->givePermissionTo(['view_any:checklist_nao_conformidade', 'view:checklist_nao_conformidade']);
        $this->actingAs($leitor);

        Livewire::test(ListChecklistNaoConformidades::class)
            ->selectTableRecords([$nc->id])
            ->callAction(TestAction::make('cancelar')->table()->bulk(), ['observacao_resolucao' => 'Tentativa.'])
            ->assertNotified(Notification::make()->title('0 canceladas, 1 ignorada')->color('warning'));

        $this->assertSame(NaoConformidadeStatus::Aberta, $nc->fresh()->status);
    }

    private function gerente(): User
    {
        return User::factory()->gerente()->create(['name_first' => 'Gerente']);
    }
}
