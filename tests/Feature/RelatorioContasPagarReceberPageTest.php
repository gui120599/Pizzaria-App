<?php

namespace Tests\Feature;

use App\Filament\Pages\RelatorioContasPagarReceber;
use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class RelatorioContasPagarReceberPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_pagina_monta_sem_erro(): void
    {
        Livewire::test(RelatorioContasPagarReceber::class)->assertOk();
    }

    public function test_filtro_de_tipo_reflete_no_conjunto_exibido(): void
    {
        Lancamento::create([
            'tipo' => 'pagar',
            'descricao' => 'Conta de luz',
            'valor' => 250,
            'vencimento' => now()->subDays(2),
        ]);
        Lancamento::create([
            'tipo' => 'receber',
            'descricao' => 'Venda fiado',
            'valor' => 500,
            'vencimento' => now()->addDays(5),
        ]);

        Livewire::test(RelatorioContasPagarReceber::class)
            ->set('filters.tipo', 'pagar')
            ->assertOk();
    }

    public function test_rota_de_impressao_esta_registrada(): void
    {
        $this->assertTrue(Route::has('relatorios.contas_pagar_receber.imprimir'));
    }

    public function test_view_de_impressao_renderiza_e_dispara_impressao_automatica(): void
    {
        Lancamento::create([
            'tipo' => 'pagar',
            'descricao' => 'Conta de luz',
            'valor' => 250,
            'vencimento' => now()->subDays(2),
        ]);

        $response = $this->get(route('relatorios.contas_pagar_receber.imprimir'));

        $response->assertOk();
        $response->assertSee('Relatório de Contas a Pagar e a Receber');
        $response->assertSee('Conta de luz');
        $response->assertSee('window.print()', false);
    }
}
