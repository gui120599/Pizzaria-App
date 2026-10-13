<?php

namespace Tests\Feature;

use App\Enums\Checklist\ExecucaoStatus;
use App\Filament\Garcom\Pages\ExecutarChecklist;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

        Livewire::test(ExecutarChecklist::class, ['execucao' => $execucao->id])
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

        Livewire::test(ExecutarChecklist::class, ['execucao' => $execucao->id])
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

        Livewire::test(ExecutarChecklist::class, ['execucao' => $execucao->id])
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

        Livewire::test(ExecutarChecklist::class, ['execucao' => $execucao->id])
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

        Livewire::test(ExecutarChecklist::class, ['execucao' => $execucao->id])
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

        Livewire::test(ExecutarChecklist::class, ['execucao' => $execucao->id])
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

        Livewire::test(ExecutarChecklist::class, ['execucao' => $execucao->id])
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

        Livewire::test(ExecutarChecklist::class, ['execucao' => $execucao->id])
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
