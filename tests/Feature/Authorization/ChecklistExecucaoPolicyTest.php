<?php

namespace Tests\Feature\Authorization;

use App\Models\ChecklistExecucao;
use App\Models\ChecklistExecucaoItem;
use App\Models\ChecklistModelo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistExecucaoPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipe_executa_so_checklists_atribuidos_ao_seu_papel(): void
    {
        $execucao = ChecklistExecucao::factory()->for(ChecklistModelo::factory()->paraPapeis(['Cozinha']), 'modelo')->create();
        $cozinheira = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $garcom = User::factory()->garcom()->create(['name_first' => 'Bruno']);

        $this->assertTrue($cozinheira->can('executar', $execucao));
        $this->assertFalse($garcom->can('executar', $execucao));
    }

    public function test_gerente_executa_checklist_de_qualquer_papel(): void
    {
        $execucao = ChecklistExecucao::factory()->for(ChecklistModelo::factory()->paraPapeis(['Cozinha']), 'modelo')->create();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        $this->assertTrue($gerente->can('executar', $execucao));
    }

    public function test_equipe_ve_so_execucoes_em_que_participou(): void
    {
        $cozinheira = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $respondeu = ChecklistExecucao::factory()->create();
        ChecklistExecucaoItem::factory()->for($respondeu, 'execucao')->respondido(por: $cozinheira)->create();
        $deOutro = ChecklistExecucao::factory()->emAndamento()->create();

        $this->assertTrue($cozinheira->can('view', $respondeu));
        $this->assertFalse($cozinheira->can('view', $deOutro));
        $this->assertFalse($cozinheira->can('viewAny', ChecklistExecucao::class));
    }

    public function test_so_a_gestao_aprova_execucoes(): void
    {
        $execucao = ChecklistExecucao::factory()->concluido()->create();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $cozinheira = User::factory()->cozinha()->create(['name_first' => 'Ana']);

        $this->assertTrue($gerente->can('aprovar', $execucao));
        $this->assertTrue($gerente->can('viewAny', ChecklistExecucao::class));
        $this->assertFalse($cozinheira->can('aprovar', $execucao));
    }
}
