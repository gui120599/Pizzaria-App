<?php

namespace Tests\Feature;

use App\Filament\Resources\Contratos\Pages\CreateContrato;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContratoFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function fornecedor(): Prestador
    {
        return Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora Sem Nome LTDA',
            'nome' => null,
        ]);
    }

    private function planoDespesa(): PlanoDespesa
    {
        return PlanoDespesa::create([
            'nome' => 'Aluguel',
            'comportamento' => 'fixo',
        ]);
    }

    public function test_cria_contrato_com_dados_validos(): void
    {
        $fornecedor = $this->fornecedor();
        $plano = $this->planoDespesa();

        Livewire::test(CreateContrato::class)
            ->fillForm([
                'descricao' => 'Aluguel do salão',
                'favorecido_id' => $fornecedor->id,
                'plano_despesa_id' => $plano->id,
                'valor' => '1500,00',
                'dia_vencimento' => 10,
                'data_inicio' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_exige_fornecedor_plano_e_dia_vencimento(): void
    {
        Livewire::test(CreateContrato::class)
            ->fillForm([
                'descricao' => 'Contrato sem vínculo',
                'favorecido_id' => null,
                'plano_despesa_id' => null,
                'dia_vencimento' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['favorecido_id', 'plano_despesa_id', 'dia_vencimento']);
    }

    /** Fornecedor PJ com `nome` nulo não pode quebrar o Select (mesmo gotcha do LancamentoForm). */
    public function test_fornecedor_sem_nome_nao_quebra_o_select(): void
    {
        $this->fornecedor();

        Livewire::test(CreateContrato::class)
            ->assertFormFieldExists('favorecido_id')
            ->assertOk();
    }
}
