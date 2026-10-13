<?php

namespace Tests\Feature;

use App\Enums\Checklist\ExecucaoStatus;
use App\Filament\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Filament\Resources\ChecklistExecucoes\Pages\ListChecklistExecucoes;
use App\Filament\Resources\ChecklistExecucoes\Pages\ViewChecklistExecucao;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChecklistExecucaoResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerente_aprova_execucao_concluida_pela_tabela(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $gerente = $this->gerente();
        $execucao = $this->execucaoConcluida();
        $this->actingAs($gerente);

        Livewire::test(ListChecklistExecucoes::class)
            ->callAction(TestAction::make('aprovar')->table($execucao))
            ->assertNotified('Checklist aprovado');

        $execucao->refresh();
        $this->assertSame(ExecucaoStatus::Aprovado, $execucao->status);
        $this->assertSame($gerente->id, $execucao->aprovado_por);
    }

    public function test_reprovar_exige_motivo(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $execucao = $this->execucaoConcluida();
        $this->actingAs($this->gerente());

        Livewire::test(ViewChecklistExecucao::class, ['record' => $execucao->id])
            ->callAction('reprovar', ['motivo' => ''])
            ->assertHasActionErrors(['motivo' => 'required']);

        $this->assertSame(ExecucaoStatus::Concluido, $execucao->fresh()->status);
    }

    public function test_reprovar_com_motivo_devolve_para_a_equipe(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $execucao = $this->execucaoConcluida();
        $this->actingAs($this->gerente());

        Livewire::test(ViewChecklistExecucao::class, ['record' => $execucao->id])
            ->callAction('reprovar', ['motivo' => 'Faltou a foto do gás.'])
            ->assertHasNoActionErrors();

        $execucao->refresh();
        $this->assertSame(ExecucaoStatus::Reprovado, $execucao->status);
        $this->assertSame('Faltou a foto do gás.', $execucao->motivo_reprovacao);
    }

    public function test_aprovar_fica_oculto_para_execucao_em_andamento(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $gerente = $this->gerente();
        $execucao = app(ChecklistExecucaoService::class)->iniciar(ChecklistModelo::factory()->comItens(1)->create(), $gerente);
        $this->actingAs($gerente);

        Livewire::test(ViewChecklistExecucao::class, ['record' => $execucao->id])
            ->assertActionHidden('aprovar')
            ->assertActionHidden('reprovar');
    }

    public function test_visualizacao_mostra_resposta_e_observacao_dos_itens(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $gerente = $this->gerente();
        $modelo = ChecklistModelo::factory()->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->temperatura(0, 5)->create(['titulo' => 'Câmara fria']);
        $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, $gerente);
        app(ChecklistExecucaoService::class)->responder($execucao->itens()->sole(), ['valor' => 7, 'observacao' => 'Porta mal fechada.'], $gerente);
        $this->actingAs($gerente);

        $this->get(ChecklistExecucaoResource::getUrl('view', ['record' => $execucao]))
            ->assertSeeText('Câmara fria')
            ->assertSeeText('7,00 °C')
            ->assertSeeText('Obs.: Porta mal fechada.');
    }

    public function test_equipe_sem_permissao_nao_lista_execucoes_no_admin(): void
    {
        $this->actingAs(User::factory()->caixa()->create(['name_first' => 'Dani']));

        $this->get(ChecklistExecucaoResource::getUrl('index'))->assertForbidden();
    }

    private function gerente(): User
    {
        return User::factory()->gerente()->create(['name_first' => 'Gerente']);
    }

    private function execucaoConcluida(): ChecklistExecucao
    {
        $cozinheira = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $servico = app(ChecklistExecucaoService::class);
        $execucao = $servico->iniciar(ChecklistModelo::factory()->comItens(1)->paraPapeis(['Cozinha'])->create(), $cozinheira);
        $servico->responder($execucao->itens()->sole(), ['valor' => true], $cozinheira);

        return $servico->finalizar($execucao, $cozinheira);
    }
}
