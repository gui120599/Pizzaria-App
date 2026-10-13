<?php

namespace Tests\Feature;

use App\Enums\Checklist\ExecucaoStatus;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Services\Checklist\ChecklistModeloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Versionamento do modelo e preservação do snapshot nas execuções. */
class ChecklistModeloServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_editar_modelo_nao_altera_o_snapshot_da_execucao_em_andamento(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        $freezer = ChecklistModeloItem::factory()->for($modelo, 'modelo')->temperatura(-25, -18)->create(['titulo' => 'Freezer']);
        $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, $this->cozinheira());
        $assinatura = $this->servico()->assinatura($modelo);

        $freezer->update(['titulo' => 'Freezer horizontal', 'valor_maximo' => -15]);
        $gerouVersao = $this->servico()->aplicarNovaVersao($modelo, $assinatura);

        $this->assertTrue($gerouVersao);
        $this->assertSame(2, $modelo->fresh()->versao);
        $item = $execucao->itens()->sole();
        $this->assertSame('Freezer', $item->titulo);
        $this->assertSame('-18.00', $item->regra('valor_maximo'));
        $this->assertSame(1, $execucao->fresh()->modelo_versao);
    }

    public function test_nova_versao_refaz_o_snapshot_da_pendente_sem_respostas(): void
    {
        $this->travelTo('2026-10-13 05:05:00');
        $modelo = ChecklistModelo::factory()->create();
        $item = ChecklistModeloItem::factory()->for($modelo, 'modelo')->create(['titulo' => 'Forno ligado']);
        app(ChecklistExecucaoService::class)->gerarPendentesDoDia(today());
        $assinatura = $this->servico()->assinatura($modelo);

        $item->update(['titulo' => 'Forno ligado e pré-aquecido']);
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->create(['titulo' => 'Gás aberto', 'ordem' => 2]);
        $this->servico()->aplicarNovaVersao($modelo, $assinatura);

        $execucao = ChecklistExecucao::sole();
        $this->assertSame(ExecucaoStatus::Pendente, $execucao->status);
        $this->assertSame(2, $execucao->modelo_versao);
        $this->assertSame(2, $execucao->total_itens);
        $this->assertSame(['Forno ligado e pré-aquecido', 'Gás aberto'], $execucao->itens()->pluck('titulo')->all());
    }

    public function test_salvar_sem_mudar_o_conteudo_nao_gera_nova_versao(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $modelo = ChecklistModelo::factory()->comItens(2)->paraPapeis(['Cozinha'])->create();
        app(ChecklistExecucaoService::class)->iniciar($modelo, $this->cozinheira());
        $assinatura = $this->servico()->assinatura($modelo);

        $modelo->update(['descricao' => 'Só a descrição mudou', 'exige_aprovacao' => true]);
        $gerouVersao = $this->servico()->aplicarNovaVersao($modelo, $assinatura);

        $this->assertFalse($gerouVersao);
        $this->assertSame(1, $modelo->fresh()->versao);
    }

    public function test_modelo_sem_execucoes_continua_na_mesma_versao_ao_ser_editado(): void
    {
        $modelo = ChecklistModelo::factory()->comItens(1)->create();
        $assinatura = $this->servico()->assinatura($modelo);

        $modelo->update(['nome' => 'Outro nome']);
        $gerouVersao = $this->servico()->aplicarNovaVersao($modelo, $assinatura);

        $this->assertFalse($gerouVersao);
        $this->assertSame(1, $modelo->fresh()->versao);
    }

    private function servico(): ChecklistModeloService
    {
        return app(ChecklistModeloService::class);
    }

    private function cozinheira(): User
    {
        return User::factory()->cozinha()->create(['name_first' => 'Ana']);
    }
}
