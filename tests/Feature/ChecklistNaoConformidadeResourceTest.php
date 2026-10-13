<?php

namespace Tests\Feature;

use App\Enums\Checklist\NaoConformidadeStatus;
use App\Filament\Resources\ChecklistNaoConformidades\Pages\EditChecklistNaoConformidade;
use App\Filament\Resources\ChecklistNaoConformidades\Pages\ListChecklistNaoConformidades;
use App\Models\ChecklistNaoConformidade;
use App\Models\User;
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

    private function gerente(): User
    {
        return User::factory()->gerente()->create(['name_first' => 'Gerente']);
    }
}
