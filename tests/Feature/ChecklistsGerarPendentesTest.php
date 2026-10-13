<?php

namespace Tests\Feature;

use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistsGerarPendentesTest extends TestCase
{
    use RefreshDatabase;

    public function test_gera_as_pendentes_do_dia_informado(): void
    {
        ChecklistModelo::factory()->dias([6])->comItens(1)->create();

        $this->artisan('checklists:gerar-pendentes', ['--data' => '2026-10-10'])
            ->expectsOutput('1 execução(ões) pendente(s) gerada(s) para 10/10/2026.')
            ->assertSuccessful();

        $this->assertSame('2026-10-10', ChecklistExecucao::sole()->data_referencia->toDateString());
    }

    public function test_data_em_formato_invalido_e_recusada(): void
    {
        $this->artisan('checklists:gerar-pendentes', ['--data' => '10/10/2026'])
            ->expectsOutput('Data inválida. Use o formato AAAA-MM-DD.')
            ->assertFailed();

        $this->assertSame(0, ChecklistExecucao::count());
    }
}
