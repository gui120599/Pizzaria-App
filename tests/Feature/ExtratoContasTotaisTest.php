<?php

namespace Tests\Feature;

use App\Filament\Resources\MovimentacoesFinanceiras\Pages\ListMovimentacoesFinanceiras;
use App\Models\ContaFinanceira;
use App\Models\MovimentacaoFinanceira;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExtratoContasTotaisTest extends TestCase
{
    use RefreshDatabase;

    public function test_totais_do_extrato_somam_entradas_e_saidas_da_pagina_e_de_tudo_que_o_filtro_seleciona(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $banco = ContaFinanceira::factory()->banco()->create();
        $outra = ContaFinanceira::factory()->cofre()->create();

        // Ordem do extrato é id desc: a página de 5 pega os 5 últimos (entrada 100 ×3, saída 40 ×2).
        foreach ([[300, 'saida'], [100, 'entrada'], [100, 'entrada'], [100, 'entrada'], [40, 'saida'], [40, 'saida']] as [$valor, $tipo]) {
            MovimentacaoFinanceira::factory()->{$tipo}()->for($banco, 'conta')->create(['valor' => $valor]);
        }
        MovimentacaoFinanceira::factory()->entrada()->for($outra, 'conta')->create(['valor' => 999]);

        Livewire::test(ListMovimentacoesFinanceiras::class)
            ->filterTable('conta_financeira_id', $banco->id)
            ->set('tableRecordsPerPage', 5)
            ->assertTableColumnSummarySet('valor', 'entradas', 300)
            ->assertTableColumnSummarySet('valor', 'saidas', 380)
            ->assertTableColumnSummarySet('valor', 'saldo', -80)
            ->assertTableColumnSummarySet('valor', 'entradas', 300, isCurrentPaginationPageOnly: true)
            ->assertTableColumnSummarySet('valor', 'saidas', 80, isCurrentPaginationPageOnly: true)
            ->assertTableColumnSummarySet('valor', 'saldo', 220, isCurrentPaginationPageOnly: true)
            ->assertSee('Esta página')
            ->assertSee('Entradas − saídas');
    }
}
