<?php

namespace Tests\Feature;

use App\Enums\Checklist\ExecucaoStatus;
use App\Filament\Garcom\Pages\ChecklistsDoDia;
use App\Filament\Garcom\Pages\ExecutarChecklist;
use App\Livewire\Checklists\ExecucaoChecklist;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Support\Cozinha\Operador;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ExecutarChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
    }

    public function test_tocar_em_feito_salva_a_resposta_na_hora(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $execucao = $this->execucao($cozinheira);
        $item = $execucao->itens()->sole();
        $this->actingAs($cozinheira);

        $this->tela($execucao)
            ->call('responder', $item->id, true)
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertTrue($item->valor_booleano);
        $this->assertTrue($item->conforme);
        $this->assertSame($cozinheira->id, $item->respondido_por);
    }

    public function test_temperatura_digitada_fora_da_faixa_fica_nao_conforme(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->temperatura(-25, -18)->create(['titulo' => 'Freezer']);
        $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, $cozinheira);
        $item = $execucao->itens()->sole();
        $this->actingAs($cozinheira);

        $this->tela($execucao)
            ->set("respostas.{$item->id}", '-12')
            ->assertHasNoErrors()
            ->assertSeeText('descreva o problema');

        $item->refresh();
        $this->assertSame('-12.00', $item->valor_numero);
        $this->assertFalse($item->conforme);
    }

    public function test_valor_invalido_mostra_o_erro_no_item_sem_salvar(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->selecao()->create();
        $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, $cozinheira);
        $item = $execucao->itens()->sole();
        $this->actingAs($cozinheira);

        $this->tela($execucao)
            ->call('responder', $item->id, 'quebrada')
            ->assertHasErrors(["respostas.{$item->id}"]);

        $this->assertNull($item->fresh()->respondido_em);
    }

    public function test_observacao_e_salva_ao_sair_do_campo(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $execucao = $this->execucao($cozinheira);
        $item = $execucao->itens()->sole();
        $this->actingAs($cozinheira);

        $this->tela($execucao)
            ->set("observacoes.{$item->id}", 'Bancada com mancha.');

        $this->assertSame('Bancada com mancha.', $item->fresh()->observacao);
    }

    public function test_foto_enviada_vira_anexo_do_item(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Storage::fake('public');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->foto()->create();
        $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, $cozinheira);
        $item = $execucao->itens()->sole();
        $this->actingAs($cozinheira);

        $this->tela($execucao)
            ->set("fotos.{$item->id}", UploadedFile::fake()->image('bancada.jpg', 1600, 1200))
            ->assertHasNoErrors()
            ->assertSet('fotos', []);

        $anexo = $item->anexos()->sole();
        Storage::disk('public')->assertExists($anexo->caminho);
        $this->assertTrue($item->fresh()->conforme);
    }

    public function test_finalizar_com_pendencia_marca_o_item_e_rola_ate_ele(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $execucao = $this->execucao($cozinheira);
        $item = $execucao->itens()->sole();
        $this->actingAs($cozinheira);

        $this->tela($execucao)
            ->call('finalizar')
            ->assertSet('erros', [$item->id => ['Responda este item.']])
            ->assertDispatched('checklist-rolar-para', itemId: $item->id)
            ->assertSeeText('Responda este item.');

        $this->assertSame(ExecucaoStatus::EmAndamento, $execucao->fresh()->status);
    }

    public function test_finalizar_completo_mostra_o_resumo_e_tira_os_controles(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $execucao = $this->execucao($cozinheira);
        $item = $execucao->itens()->sole();
        $this->actingAs($cozinheira);

        $this->tela($execucao)
            ->call('responder', $item->id, true)
            ->set('observacaoGeral', 'Tudo em ordem.')
            ->call('finalizar')
            ->assertNotified('Checklist finalizado')
            ->assertSeeText('Voltar aos checklists')
            ->assertDontSeeText('Finalizar checklist');

        $execucao->refresh();
        $this->assertSame(ExecucaoStatus::Concluido, $execucao->status);
        $this->assertSame('Tudo em ordem.', $execucao->observacao_geral);
    }

    public function test_execucao_reprovada_mostra_o_motivo_e_volta_a_aceitar_respostas_ao_corrigir(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $servico = app(ChecklistExecucaoService::class);
        $execucao = $this->execucao($cozinheira);
        $servico->responder($execucao->itens()->sole(), ['valor' => true], $cozinheira);
        $servico->finalizar($execucao, $cozinheira);
        $servico->reprovar($execucao, User::factory()->gerente()->create(['name_first' => 'Gerente']), 'Bancada ainda suja.');
        $this->actingAs($cozinheira);

        $this->tela($execucao)
            ->assertSeeText('Bancada ainda suja.')
            ->call('reabrir')
            ->assertSeeText('Finalizar checklist');

        $this->assertSame(ExecucaoStatus::EmAndamento, $execucao->fresh()->status);
    }

    public function test_quem_nao_tem_o_papel_nem_participou_nao_abre_a_execucao(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $execucao = $this->execucao($this->cozinheira());
        $this->actingAs(User::factory()->garcom()->create(['name_first' => 'Bruno']));

        $this->get(ExecutarChecklist::getUrl(['execucao' => $execucao->id]))->assertForbidden();
    }

    public function test_pagina_do_garcom_hospeda_a_execucao_no_layout_celular(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $execucao = $this->execucao($cozinheira);
        $this->actingAs($cozinheira);

        $this->get(ExecutarChecklist::getUrl(['execucao' => $execucao->id]))
            ->assertOk()
            ->assertSeeLivewire(ExecucaoChecklist::class)
            ->assertSeeText('Finalizar checklist')
            ->assertDontSee('Seções do checklist');
    }

    public function test_layout_tablet_mostra_as_secoes_e_responde_em_nome_do_operador(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $estacao = User::factory()->create(['name_first' => 'Tablet Cozinha']);
        $ana = Operador::doUsuario($this->cozinheira());
        $execucao = app(ChecklistExecucaoService::class)->iniciar(
            ChecklistModelo::factory()->comItens(1)->paraPapeis(['Cozinha'])->create(), $estacao, $ana);
        $item = $execucao->itens()->sole();
        $this->actingAs($estacao);

        Livewire::test(ExecucaoChecklist::class, ['execucaoId' => $execucao->id, 'urlLista' => '/cozinha/checklists', 'layout' => 'tablet', 'operador' => $ana])
            ->assertSee('Seções do checklist')
            ->assertSeeText('Operador:')
            ->assertSeeText('Ana')
            ->call('responder', $item->id, true)
            ->assertHasNoErrors();

        $this->assertTrue($item->fresh()->conforme);
    }

    public function test_conta_da_estacao_sem_operador_so_le_o_checklist(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $execucao = $this->execucao($this->cozinheira());
        $estacao = User::factory()->gerente()->create(['name_first' => 'Tablet Cozinha']);
        $this->actingAs($estacao);

        Livewire::test(ExecucaoChecklist::class, ['execucaoId' => $execucao->id, 'urlLista' => '/cozinha/checklists', 'layout' => 'tablet', 'operador' => new Operador(null, 3, 'Marcos')])
            ->assertDontSeeText('Finalizar checklist');
    }

    private function tela(ChecklistExecucao $execucao): Testable
    {
        return Livewire::test(ExecucaoChecklist::class, ['execucaoId' => $execucao->id, 'urlLista' => ChecklistsDoDia::getUrl()]);
    }

    private function cozinheira(): User
    {
        return User::factory()->cozinha()->create(['name_first' => 'Ana']);
    }

    private function execucao(User $user): ChecklistExecucao
    {
        $modelo = ChecklistModelo::factory()->comItens(1)->paraPapeis(['Cozinha'])->create();

        return app(ChecklistExecucaoService::class)->iniciar($modelo, $user);
    }
}
