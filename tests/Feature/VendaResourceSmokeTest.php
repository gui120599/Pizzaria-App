<?php

namespace Tests\Feature;

use App\Filament\Resources\Vendas\Pages\ListVendas;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendaResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_lista_de_vendas_monta_sem_erro(): void
    {
        Livewire::test(ListVendas::class)->assertOk();
    }

    public function test_lista_mostra_acao_operar_apenas_para_venda_iniciada(): void
    {
        $iniciada = Venda::create(['venda_status' => 'INICIADA']);
        $finalizada = Venda::create(['venda_status' => 'FINALIZADA']);

        $component = Livewire::test(ListVendas::class);

        $component->assertTableActionVisible('operar', $iniciada);
        $component->assertTableActionHidden('operar', $finalizada);
    }

    public function test_lista_mostra_acao_de_danfe_apenas_quando_ha_nfe_emitida(): void
    {
        $comNfe = Venda::create(['venda_status' => 'FINALIZADA', 'venda_id_nfe' => 'inv-123']);
        $semNfe = Venda::create(['venda_status' => 'FINALIZADA']);

        $component = Livewire::test(ListVendas::class);

        $component->assertTableActionVisible('imprimirDanfe', $comNfe);
        $component->assertTableActionHidden('imprimirDanfe', $semNfe);
    }

    public function test_lista_mostra_acao_de_cancelar_nfe_apenas_quando_status_e_issued(): void
    {
        $emitida = Venda::create(['venda_status' => 'FINALIZADA', 'venda_id_nfe' => 'inv-123', 'venda_status_nfe' => 'Issued']);
        $naoEmitida = Venda::create(['venda_status' => 'FINALIZADA']);

        $component = Livewire::test(ListVendas::class);

        $component->assertTableActionVisible('cancelarNfe', $emitida);
        $component->assertTableActionHidden('cancelarNfe', $naoEmitida);
    }
}
