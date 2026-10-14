<?php

namespace Tests\Feature;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExecucaoStatus;
use App\Filament\Garcom\Pages\ChecklistsDoDia;
use App\Filament\Garcom\Pages\ExecutarChecklist;
use App\Livewire\Checklists\ChecklistsDoDia as ListaDeChecklists;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Services\Checklist\ChecklistsDoDiaService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChecklistsDoDiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
    }

    public function test_lista_em_andamento_depois_na_janela_futuros_e_encerrados_so_dos_papeis_do_usuario(): void
    {
        $this->travelTo('2026-10-13 17:00:00');
        $cozinheira = $this->cozinheira();
        $servico = app(ChecklistExecucaoService::class);
        $concluido = $this->modelo('Limpeza coifa', '15:00:00', '16:00:00');
        $futuro = $this->modelo('Fechamento cozinha', '22:30:00', '01:30:00');
        $naJanela = $this->modelo('Abertura cozinha', '16:30:00', '18:30:00');
        $emAndamento = $this->modelo('Temperaturas', '16:00:00', '23:00:00');
        $this->modelo('Abertura salão', '16:30:00', '18:30:00', ['Garcom']);
        $execucaoConcluida = $servico->iniciar($concluido, $cozinheira);
        $servico->responder($execucaoConcluida->itens()->sole(), ['valor' => true], $cozinheira);
        $servico->finalizar($execucaoConcluida, $cozinheira);
        $servico->iniciar($emAndamento, $cozinheira);

        $hoje = app(ChecklistsDoDiaService::class)->para($cozinheira)['hoje'];

        $this->assertSame(
            ['Temperaturas', 'Abertura cozinha', 'Fechamento cozinha', 'Limpeza coifa'],
            array_column($hoje, 'nome'),
        );
        $this->assertSame(
            [ChecklistsDoDiaService::EM_ANDAMENTO, ChecklistsDoDiaService::NA_JANELA, ChecklistsDoDiaService::FUTURO, ChecklistsDoDiaService::ENCERRADO],
            array_column($hoje, 'situacao'),
        );
        $this->assertNull($hoje[1]['execucao_id']);
        $this->assertSame($naJanela->id, $hoje[1]['modelo_id']);
        $this->assertSame($futuro->id, $hoje[2]['modelo_id']);
    }

    public function test_modelo_sob_demanda_fica_em_outros_checklists(): void
    {
        $this->travelTo('2026-10-13 17:00:00');
        $sobDemanda = ChecklistModelo::factory()->sobDemanda()->comItens(1)->paraPapeis(['Cozinha'])->create(['nome' => 'Limpeza pesada']);

        $checklists = app(ChecklistsDoDiaService::class)->para($this->cozinheira());

        $this->assertSame([], $checklists['hoje']);
        $this->assertSame([$sobDemanda->id], array_map(fn (ChecklistModelo $modelo): int => $modelo->id, $checklists['outros']));
    }

    public function test_badge_conta_so_os_pendentes_com_a_janela_aberta(): void
    {
        $this->travelTo('2026-10-13 17:00:00');
        $cozinheira = $this->cozinheira();
        $this->modelo('Abertura cozinha', '16:30:00', '18:30:00');
        $this->modelo('Fechamento cozinha', '22:30:00', '01:30:00');
        $this->actingAs($cozinheira);

        $this->assertSame('1', ChecklistsDoDia::getNavigationBadge());
    }

    public function test_iniciar_cria_a_execucao_e_abre_a_tela_de_execucao(): void
    {
        $this->travelTo('2026-10-13 17:00:00');
        $cozinheira = $this->cozinheira();
        $modelo = $this->modelo('Abertura cozinha', '16:30:00', '18:30:00');
        $this->actingAs($cozinheira);

        $pagina = Livewire::test(ListaDeChecklists::class, ['paginaExecucao' => ExecutarChecklist::class])->call('iniciar', $modelo->id);

        $execucao = ChecklistExecucao::sole();
        $pagina->assertRedirect(ExecutarChecklist::getUrl(['execucao' => $execucao->id]));
        $this->assertSame(ExecucaoStatus::EmAndamento, $execucao->status);
        $this->assertSame($cozinheira->id, $execucao->iniciado_por);
    }

    public function test_iniciar_modelo_de_outro_papel_e_recusado(): void
    {
        $this->travelTo('2026-10-13 17:00:00');
        $modelo = $this->modelo('Abertura salão', '16:30:00', '18:30:00', ['Garcom']);
        $this->actingAs($this->cozinheira());

        Livewire::test(ListaDeChecklists::class, ['paginaExecucao' => ExecutarChecklist::class])
            ->call('iniciar', $modelo->id)
            ->assertNotified('Você não está atribuído a este checklist.')
            ->assertNoRedirect();

        $this->assertSame(0, ChecklistExecucao::count());
    }

    public function test_abertura_mostra_pendencias_do_ultimo_fechamento_do_mesmo_setor(): void
    {
        $this->travelTo('2026-10-12 23:00:00');
        $cozinheira = $this->cozinheira();
        $servico = app(ChecklistExecucaoService::class);
        $fechamento = ChecklistModelo::factory()->tipo(ChecklistTipo::Fechamento)->setor(ChecklistSetor::Cozinha)
            ->janela('22:30:00', '01:30:00')->comItens(1)->paraPapeis(['Cozinha'])->create();
        $execucaoFechamento = $servico->iniciar($fechamento, $cozinheira);
        $servico->responder($execucaoFechamento->itens()->sole(), ['valor' => false, 'observacao' => 'Freezer 2 com gelo na porta.'], $cozinheira);
        $servico->finalizar($execucaoFechamento, $cozinheira, 'Faltou mussarela, pedir ao fornecedor.');
        $this->travelTo('2026-10-13 16:10:00');
        $abertura = $servico->iniciar(ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->comItens(1)->paraPapeis(['Cozinha'])->create(), $cozinheira);

        $pendencias = app(ChecklistsDoDiaService::class)->pendenciasDoUltimoFechamento($abertura);

        $this->assertSame($execucaoFechamento->id, $pendencias['fechamento']->id);
        $this->assertCount(1, $pendencias['naoConformidades']);
        $this->assertSame(['Faltou mussarela, pedir ao fornecedor.'], $pendencias['recados']);
    }

    public function test_abertura_ignora_fechamento_de_outro_setor(): void
    {
        $this->travelTo('2026-10-12 23:00:00');
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $servico = app(ChecklistExecucaoService::class);
        $fechamentoSalao = ChecklistModelo::factory()->tipo(ChecklistTipo::Fechamento)->setor(ChecklistSetor::Salao)
            ->janela('22:30:00', '01:30:00')->comItens(1)->create();
        $execucao = $servico->iniciar($fechamentoSalao, $gerente);
        $servico->responder($execucao->itens()->sole(), ['valor' => false, 'observacao' => 'Porta emperrada.'], $gerente);
        $servico->finalizar($execucao, $gerente);
        $this->travelTo('2026-10-13 16:10:00');
        $abertura = $servico->iniciar(ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->comItens(1)->create(), $gerente);

        $pendencias = app(ChecklistsDoDiaService::class)->pendenciasDoUltimoFechamento($abertura);

        $this->assertNull($pendencias['fechamento']);
    }

    public function test_com_o_painel_cozinha_ligado_a_lista_do_garcom_esconde_os_checklists_da_cozinha(): void
    {
        $this->travelTo('2026-10-13 17:00:00');
        config(['cozinha.checklists.exibir_tambem_no_garcom' => false]);
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $cozinha = ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->comItens(1)->create(['nome' => 'Abertura cozinha']);
        ChecklistModelo::factory()->setor(ChecklistSetor::Salao)->comItens(1)->create(['nome' => 'Abertura salão']);
        $this->actingAs($gerente);

        $lista = Livewire::test(ListaDeChecklists::class, ['paginaExecucao' => ExecutarChecklist::class])
            ->assertSeeText('Abertura salão')
            ->assertDontSeeText('Abertura cozinha');

        $this->expectException(ModelNotFoundException::class);
        $lista->call('iniciar', $cozinha->id);
    }

    public function test_pagina_do_garcom_hospeda_a_lista_com_o_historico(): void
    {
        $this->travelTo('2026-10-13 17:00:00');
        $this->modelo('Abertura cozinha', '16:30:00', '18:30:00');
        $this->actingAs($this->cozinheira());

        $this->get(ChecklistsDoDia::getUrl())
            ->assertOk()
            ->assertSeeLivewire(ListaDeChecklists::class)
            ->assertSeeText('Abertura cozinha')
            ->assertSeeText('Meu histórico de checklists');
    }

    private function cozinheira(): User
    {
        return User::factory()->cozinha()->create(['name_first' => 'Ana']);
    }

    /** @param  list<string>  $papeis */
    private function modelo(string $nome, string $inicio, string $fim, array $papeis = ['Cozinha']): ChecklistModelo
    {
        return ChecklistModelo::factory()->janela($inicio, $fim)->comItens(1)->paraPapeis($papeis)->create(['nome' => $nome]);
    }
}
