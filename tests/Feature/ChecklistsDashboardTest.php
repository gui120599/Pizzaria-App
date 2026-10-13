<?php

namespace Tests\Feature;

use App\Filament\Pages\ChecklistsDashboard;
use App\Filament\Widgets\ChecklistConformidadeStatsOverview;
use App\Filament\Widgets\ChecklistItensMaisFalhamWidget;
use App\Filament\Widgets\ChecklistsHojeWidget;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChecklistsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerente_abre_o_painel_de_checklists(): void
    {
        $this->actingAs($this->gerente());

        $this->get(ChecklistsDashboard::getUrl())->assertSeeText('Painel de Checklists');
    }

    public function test_caixa_sem_permissao_nao_abre_o_painel(): void
    {
        $this->actingAs(User::factory()->caixa()->create(['name_first' => 'Dani']));

        $this->get(ChecklistsDashboard::getUrl())->assertForbidden();
    }

    public function test_checklists_de_hoje_mostra_status_e_progresso_de_cada_modelo(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $gerente = $this->gerente();
        $iniciado = ChecklistModelo::factory()->comItens(3)->create(['nome' => 'Abertura Cozinha']);
        ChecklistModelo::factory()->comItens(1)->create(['nome' => 'Abertura Salão']);
        ChecklistModelo::factory()->dias([1])->comItens(1)->create(['nome' => 'Só segunda']);
        $execucao = app(ChecklistExecucaoService::class)->iniciar($iniciado, $gerente);
        app(ChecklistExecucaoService::class)->responder($execucao->itens()->first(), ['valor' => true], $gerente);
        $this->actingAs($gerente);

        Livewire::test(ChecklistsHojeWidget::class)
            ->assertSeeText('Abertura Cozinha')
            ->assertSeeText('Em andamento')
            ->assertSeeText('1/3')
            ->assertSeeText('Abertura Salão')
            ->assertSeeText('Não gerado')
            ->assertDontSeeText('Só segunda');
    }

    public function test_itens_que_mais_falham_ordena_pelo_numero_de_falhas(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $gerente = $this->gerente();
        $servico = app(ChecklistExecucaoService::class);
        foreach (['Freezer', 'Freezer', 'Câmara fria'] as $titulo) {
            $modelo = ChecklistModelo::factory()->multiplasPorDia()->create(['nome' => 'Equipamentos']);
            ChecklistModeloItem::factory()->for($modelo, 'modelo')->create(['titulo' => $titulo]);
            $servico->responder($servico->iniciar($modelo, $gerente)->itens()->sole(), ['valor' => false], $gerente);
        }
        $this->actingAs($gerente);

        Livewire::test(ChecklistItensMaisFalhamWidget::class)
            ->assertSeeTextInOrder(['Freezer', '2 falhas', 'Câmara fria', '1 falha']);
    }

    public function test_indicadores_mostram_media_de_conformidade_e_ncs_abertas(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $gerente = $this->gerente();
        $servico = app(ChecklistExecucaoService::class);
        $modelo = ChecklistModelo::factory()->comItens(2)->create();
        $execucao = $servico->iniciar($modelo, $gerente);
        [$primeiro, $segundo] = $execucao->itens()->get()->all();
        $servico->responder($primeiro, ['valor' => true], $gerente);
        $servico->responder($segundo, ['valor' => false, 'observacao' => 'Quebrado.'], $gerente);
        $servico->finalizar($execucao, $gerente);
        $this->actingAs($gerente);

        Livewire::test(ChecklistConformidadeStatsOverview::class)
            ->assertSeeText('50,0%')
            ->assertSeeText('Não conformidades abertas');
    }

    private function gerente(): User
    {
        return User::factory()->gerente()->create(['name_first' => 'Gerente']);
    }
}
