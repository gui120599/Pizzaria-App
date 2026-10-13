<?php

namespace Tests\Feature;

use App\Filament\Garcom\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Filament\Garcom\Resources\ChecklistExecucoes\Pages\ListChecklistExecucoes;
use App\Models\ChecklistModelo;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HistoricoChecklistGarcomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
    }

    public function test_historico_lista_so_as_execucoes_em_que_o_usuario_participou(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $ana = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $bia = User::factory()->cozinha()->create(['name_first' => 'Bia']);
        $servico = app(ChecklistExecucaoService::class);
        $daAna = $servico->iniciar(ChecklistModelo::factory()->comItens(1)->paraPapeis(['Cozinha'])->create(), $ana);
        $daBia = $servico->iniciar(ChecklistModelo::factory()->comItens(1)->paraPapeis(['Cozinha'])->create(), $bia);
        $this->actingAs($ana);

        Livewire::test(ListChecklistExecucoes::class)
            ->assertCanSeeTableRecords([$daAna])
            ->assertCanNotSeeTableRecords([$daBia]);
    }

    public function test_execucao_de_outra_pessoa_nao_abre_pelo_historico(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $daBia = app(ChecklistExecucaoService::class)->iniciar(
            ChecklistModelo::factory()->comItens(1)->paraPapeis(['Cozinha'])->create(),
            User::factory()->cozinha()->create(['name_first' => 'Bia']),
        );
        $this->actingAs(User::factory()->cozinha()->create(['name_first' => 'Ana']));

        $this->get(ChecklistExecucaoResource::getUrl('view', ['record' => $daBia]))->assertNotFound();
    }
}
