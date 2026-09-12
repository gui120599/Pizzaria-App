<?php

namespace Tests\Feature;

use App\Enums\StatusFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\Pages\ListFechamentosCaixa;
use App\Models\Caixa;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\ImportacaoCaixaReceberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre a integração entre o fechamento de caixa e a importação pro Contas a
 * Receber (App\Services\ImportacaoCaixaReceberService) pela camada Filament:
 * confirmar importa automaticamente, reabrir é bloqueado se já importado, e as
 * actions manuais (ImportarReceberAction/EstornarImportacaoReceberAction).
 */
class FechamentoCaixaImportacaoActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function sessaoComVenda(float $valor = 100.0): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);

        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $opcao = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
        $venda = Venda::create([
            'venda_sessao_caixa_id' => $sessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => 0,
        ]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => $valor,
        ]);

        return $sessao;
    }

    public function test_confirmar_fechamento_importa_automaticamente_para_contas_a_receber(): void
    {
        $sessao = $this->sessaoComVenda();
        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        Livewire::test(ListFechamentosCaixa::class)
            ->callTableAction('confirmarFechamento', $fechamento)
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatusFechamentoCaixa::Confirmado, $fechamento->fresh()->status);
        $lancamento = Lancamento::where('sessao_caixa_id', $sessao->id)->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $lancamento->valor, 0.01);
    }

    public function test_reabrir_fechamento_e_bloqueado_quando_ha_importacao(): void
    {
        $sessao = $this->sessaoComVenda();
        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Confirmado,
            'confirmado_em' => now(),
        ]);
        app(ImportacaoCaixaReceberService::class)->importar($sessao);

        // A action chama $action->halt() (não é erro de validação de formulário —
        // não há schema nesta action — por isso não usamos assertHasNoTableActionErrors()
        // aqui), então o status não muda.
        Livewire::test(ListFechamentosCaixa::class)
            ->callTableAction('reabrirFechamento', $fechamento);

        $this->assertSame(StatusFechamentoCaixa::Confirmado, $fechamento->fresh()->status);
    }

    public function test_reabrir_fechamento_funciona_apos_estornar_a_importacao(): void
    {
        $sessao = $this->sessaoComVenda();
        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Confirmado,
            'confirmado_em' => now(),
        ]);
        app(ImportacaoCaixaReceberService::class)->importar($sessao);

        Livewire::test(ListFechamentosCaixa::class)
            ->callTableAction('estornarImportacaoReceber', $fechamento)
            ->assertHasNoTableActionErrors()
            ->callTableAction('reabrirFechamento', $fechamento)
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatusFechamentoCaixa::Rascunho, $fechamento->fresh()->status);
        $this->assertSame(0, Lancamento::where('sessao_caixa_id', $sessao->id)->count());
    }

    public function test_importar_receber_action_visivel_so_quando_confirmado_e_nao_importado(): void
    {
        $sessao = $this->sessaoComVenda();
        $rascunho = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        $component = Livewire::test(ListFechamentosCaixa::class);
        $component->assertTableActionHidden('importarReceber', $rascunho);

        $rascunho->update(['status' => StatusFechamentoCaixa::Confirmado, 'confirmado_em' => now()]);
        $component = Livewire::test(ListFechamentosCaixa::class);
        $component->assertTableActionVisible('importarReceber', $rascunho->fresh());

        app(ImportacaoCaixaReceberService::class)->importar($sessao);
        $component = Livewire::test(ListFechamentosCaixa::class);
        $component->assertTableActionHidden('importarReceber', $rascunho->fresh());
        $component->assertTableActionVisible('estornarImportacaoReceber', $rascunho->fresh());
    }
}
