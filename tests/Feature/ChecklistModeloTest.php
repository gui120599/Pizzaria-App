<?php

namespace Tests\Feature;

use App\Enums\Checklist\ChecklistSetor;
use App\Models\ChecklistModelo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistModeloTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicar_copia_secoes_itens_e_papeis_numa_copia_inativa(): void
    {
        $modelo = ChecklistModelo::factory()->comItens(3)->paraPapeis(['Cozinha', 'Gerente'])->create(['nome' => 'Abertura Cozinha']);
        $modelo->increment('versao');

        $copia = $modelo->duplicar();

        $this->assertSame('Abertura Cozinha (cópia)', $copia->nome);
        $this->assertFalse($copia->ativo);
        $this->assertSame(1, $copia->versao);
        $this->assertSame(['Cozinha', 'Gerente'], $copia->roles()->orderBy('name')->pluck('name')->all());
        $this->assertSame($modelo->itens()->pluck('titulo')->all(), $copia->itens()->pluck('titulo')->all());
        $this->assertSame(3, $copia->itens()->whereIn('checklist_modelo_secao_id', $copia->secoes()->pluck('id'))->count());
        $this->assertSame(3, $modelo->itens()->count());
    }

    public function test_equipe_so_ve_modelos_atribuidos_aos_seus_papeis_e_gestao_ve_todos(): void
    {
        $cozinha = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        $salao = ChecklistModelo::factory()->paraPapeis(['Garcom'])->create();
        $cozinheira = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        $daCozinheira = ChecklistModelo::disponiveisPara($cozinheira)->pluck('id')->all();
        $doGerente = ChecklistModelo::disponiveisPara($gerente)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$cozinha->id], $daCozinheira);
        $this->assertSame([$cozinha->id, $salao->id], $doGerente);
    }

    public function test_sem_o_modulo_de_cozinha_o_garcom_continua_vendo_os_checklists_da_cozinha(): void
    {
        $cozinha = ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->create();
        $salao = ChecklistModelo::factory()->setor(ChecklistSetor::Salao)->create();

        $this->assertEqualsCanonicalizing([$cozinha->id, $salao->id], ChecklistModelo::paraPainel(ChecklistModelo::PAINEL_GARCOM)->pluck('id')->all());
        $this->assertSame([$cozinha->id], ChecklistModelo::paraPainel(ChecklistModelo::PAINEL_COZINHA)->pluck('id')->all());
        $this->assertSame('Cozinha e Garçom', $cozinha->painelDescricao());
    }

    public function test_com_o_painel_cozinha_os_checklists_da_cozinha_saem_do_garcom(): void
    {
        config(['cozinha.checklists.exibir_tambem_no_garcom' => false, 'cozinha.checklists.setores_checklist' => ['cozinha', 'delivery']]);
        $cozinha = ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->create();
        $delivery = ChecklistModelo::factory()->setor(ChecklistSetor::Delivery)->create();
        $salao = ChecklistModelo::factory()->setor(ChecklistSetor::Salao)->create();

        $this->assertSame([$salao->id], ChecklistModelo::paraPainel(ChecklistModelo::PAINEL_GARCOM)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$cozinha->id, $delivery->id], ChecklistModelo::paraPainel(ChecklistModelo::PAINEL_COZINHA)->pluck('id')->all());
        $this->assertSame(3, ChecklistModelo::paraPainel(ChecklistModelo::PAINEL_ADMIN)->count());
        $this->assertSame('Cozinha', $cozinha->painelDescricao());
        $this->assertSame('Garçom', $salao->painelDescricao());
    }
}
