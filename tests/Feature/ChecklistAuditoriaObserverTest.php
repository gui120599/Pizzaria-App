<?php

namespace Tests\Feature;

use App\Models\ChecklistExecucao;
use App\Models\ChecklistHistorico;
use App\Models\ChecklistModelo;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistAuditoriaObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_mudanca_de_status_da_execucao_fica_registrada_com_quem_fez(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $execucao = app(ChecklistExecucaoService::class)->iniciar(ChecklistModelo::factory()->comItens(1)->paraPapeis(['Cozinha'])->create(), $cozinheira);
        $this->actingAs($cozinheira);

        app(ChecklistExecucaoService::class)->responder($execucao->itens()->first(), ['valor' => true], $cozinheira);
        app(ChecklistExecucaoService::class)->finalizar($execucao, $cozinheira);

        $historico = $execucao->historicos()->where('evento', 'status_alterado')->sole();
        $this->assertSame(['de' => 'em_andamento', 'para' => 'concluido'], $historico->dados['status']);
        $this->assertSame($cozinheira->id, $historico->user_id);
    }

    public function test_resposta_do_item_fica_registrada_e_contadores_da_execucao_nao(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $execucao = app(ChecklistExecucaoService::class)->iniciar(ChecklistModelo::factory()->comItens(1)->paraPapeis(['Cozinha'])->create(), $cozinheira);
        $item = $execucao->itens()->first();

        app(ChecklistExecucaoService::class)->responder($item, ['valor' => false], $cozinheira);

        $this->assertSame(['respondido'], $item->historicos()->pluck('evento')->all());
        $this->assertSame(['criado'], ChecklistHistorico::where('auditavel_type', ChecklistExecucao::class)->pluck('evento')->all());
    }
}
