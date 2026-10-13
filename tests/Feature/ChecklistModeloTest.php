<?php

namespace Tests\Feature;

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
}
