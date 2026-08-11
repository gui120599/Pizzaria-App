<?php

namespace Tests\Feature;

use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Models\Compra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImprimirDanfeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        Storage::fake('local');
    }

    private function compraComXml(): Compra
    {
        $xml = file_get_contents(base_path('tests/Fixtures/nfe-danfe-exemplo.xml'));
        $path = 'compras_xml/2026/01/35260114200166000166550010000000461123456789.xml';
        Storage::disk('local')->put($path, $xml);

        return Compra::create([
            'compra_chave_nfe' => '35260114200166000166550010000000461123456789',
            'compra_status' => 'rascunho',
            'compra_xml_path' => $path,
            'compra_origem' => 'xml',
        ]);
    }

    public function test_imprimir_danfe_baixa_o_pdf(): void
    {
        $compra = $this->compraComXml();

        Livewire::test(ListCompras::class)
            ->callTableAction('imprimirDanfe', $compra)
            ->assertFileDownloaded('DANFE-35260114200166000166550010000000461123456789.pdf');
    }

    public function test_acao_nao_aparece_para_compra_sem_xml(): void
    {
        $compra = Compra::create(['compra_status' => 'rascunho', 'compra_origem' => 'manual']);

        Livewire::test(ListCompras::class)
            ->assertTableActionHidden('imprimirDanfe', $compra);
    }
}
