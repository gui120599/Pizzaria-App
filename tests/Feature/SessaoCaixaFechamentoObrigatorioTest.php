<?php

namespace Tests\Feature;

use App\Filament\Resources\Caixas\Pages\EditCaixa;
use App\Models\Caixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\SessaoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SessaoCaixaFechamentoObrigatorioTest extends TestCase
{
    use RefreshDatabase;

    private function caixaComSessaoSemFechamento(bool $fechamentoObrigatorio): Caixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1', 'caixa_fechamento_obrigatorio' => $fechamentoObrigatorio]);

        SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Operador'])->id,
        ]);

        return $caixa;
    }

    public function test_caixa_novo_exige_fechamento_por_padrao(): void
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        $this->assertTrue($caixa->fresh()->caixa_fechamento_obrigatorio);
    }

    public function test_fechamento_obrigatorio_bloqueia_abertura_sem_fechamento_confirmado(): void
    {
        $caixa = $this->caixaComSessaoSemFechamento(fechamentoObrigatorio: true);

        $this->assertNotNull(app(SessaoCaixaService::class)->motivoBloqueioAbertura($caixa->id));
    }

    public function test_fechamento_opcional_libera_abertura_sem_fechamento_confirmado(): void
    {
        $caixa = $this->caixaComSessaoSemFechamento(fechamentoObrigatorio: false);

        $this->assertNull(app(SessaoCaixaService::class)->motivoBloqueioAbertura($caixa->id));
    }

    public function test_opcao_e_editavel_no_cadastro_do_caixa(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        Livewire::test(EditCaixa::class, ['record' => $caixa->id])
            ->fillForm(['caixa_fechamento_obrigatorio' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($caixa->fresh()->caixa_fechamento_obrigatorio);
    }
}
