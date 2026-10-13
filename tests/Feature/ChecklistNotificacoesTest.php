<?php

namespace Tests\Feature;

use App\Filament\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Models\ChecklistModelo;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Notificações gravadas de verdade no banco, no formato do sino do Filament. */
class ChecklistNotificacoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_nao_realizado_chega_no_sino_do_gerente_com_link_para_a_execucao(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $modelo = ChecklistModelo::factory()->janela('16:00:00', '18:30:00')->comItens(1)->create(['nome' => 'Abertura Cozinha']);
        $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, $gerente);

        app(ChecklistExecucaoService::class)->expirarForaDaJanela(now()->setTime(19, 1));

        $notificacao = $gerente->notifications()->sole();
        $this->assertSame('Checklist não realizado', $notificacao->data['title']);
        $this->assertSame('Abertura Cozinha de 13/10/2026 passou do horário (0 de 1 itens respondidos).', $notificacao->data['body']);
        $this->assertSame(ChecklistExecucaoResource::getUrl('view', ['record' => $execucao], panel: 'admin'), $notificacao->data['actions'][0]['url']);
    }
}
