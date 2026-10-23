<?php

namespace Tests\Feature;

use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoNotaMoeda;
use App\Filament\Resources\FechamentosCaixa\Pages\ListFechamentosCaixa;
use App\Models\Caixa;
use App\Models\ContaFinanceira;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\NotaMoeda;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\PlanoReceita;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\FechamentoCaixaService;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Integração entre o fechamento de caixa e a contabilização nas contas
 * (ContabilizacaoFechamentoService) pela camada Filament: confirmar contabiliza
 * junto, reabrir estorna e as actions manuais.
 */
class FechamentoCaixaImportacaoActionsTest extends TestCase
{
    use RefreshDatabase;

    private ContaFinanceira $caixaPdv;

    private ContaFinanceira $cofre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->caixaPdv = ContaFinanceira::factory()->caixaPdv()->create();
        $this->cofre = ContaFinanceira::factory()->cofre()->create();
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

        $opcao = OpcoesPagamento::create([
            'opcaopag_nome' => 'Dinheiro',
            'opcaopag_desc_nfe' => 'cash',
            'plano_receita_id' => PlanoReceita::create(['nome' => 'Balcão'])->id,
        ]);
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

    /** Rascunho com o dinheiro contado igual ao esperado e o troco já definido. */
    private function rascunho(SessaoCaixa $sessao, float $contado = 100.0, ?float $troco = 0.0): FechamentoCaixa
    {
        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
            'valor_troco_gaveta' => $troco,
            'recolhimento_conta_id' => $this->cofre->id,
        ]);
        $nota = NotaMoeda::create(['descricao' => 'Real', 'valor' => 1, 'tipo' => TipoNotaMoeda::Cedula, 'ordem_exibicao' => 1]);
        $fechamento->notas()->create(['nota_moeda_id' => $nota->id, 'quantidade' => (int) $contado]);
        app(FechamentoCaixaService::class)->criarOuAtualizarRascunho($sessao, $fechamento);

        return $fechamento->refresh();
    }

    private function confirmado(FechamentoCaixa $fechamento): FechamentoCaixa
    {
        $fechamento->update(['status' => StatusFechamentoCaixa::Confirmado, 'confirmado_em' => now()]);

        return $fechamento;
    }

    public function test_confirmar_fechamento_contabiliza_nas_contas(): void
    {
        $sessao = $this->sessaoComVenda();
        $fechamento = $this->rascunho($sessao);

        Livewire::test(ListFechamentosCaixa::class)
            ->callTableAction('confirmarFechamento', $fechamento)
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatusFechamentoCaixa::Confirmado, $fechamento->fresh()->status);
        $lancamento = Lancamento::where('sessao_caixa_id', $sessao->id)->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $lancamento->valor, 0.01);
        $this->assertSame(100.0, (float) $this->cofre->fresh()->saldo_atual);
        $this->assertSame(0.0, (float) $this->caixaPdv->fresh()->saldo_atual);
    }

    public function test_confirmar_com_configuracao_pendente_nao_confirma_nem_grava(): void
    {
        $sessao = $this->sessaoComVenda();
        $fechamento = $this->rascunho($sessao);
        $this->caixaPdv->delete();

        Livewire::test(ListFechamentosCaixa::class)
            ->callTableAction('confirmarFechamento', $fechamento);

        $this->assertSame(StatusFechamentoCaixa::Rascunho, $fechamento->fresh()->status);
        $this->assertSame(0, Lancamento::count());
    }

    public function test_confirmar_sem_troco_informado_nao_confirma(): void
    {
        $sessao = $this->sessaoComVenda();
        $fechamento = $this->rascunho($sessao, troco: null);

        Livewire::test(ListFechamentosCaixa::class)
            ->callTableAction('confirmarFechamento', $fechamento);

        $this->assertSame(StatusFechamentoCaixa::Rascunho, $fechamento->fresh()->status);
    }

    public function test_reabrir_fechamento_estorna_a_contabilizacao(): void
    {
        $sessao = $this->sessaoComVenda();
        $fechamento = $this->rascunho($sessao);
        $this->confirmado($fechamento);
        app(ContabilizacaoFechamentoService::class)->contabilizar($sessao);

        Livewire::test(ListFechamentosCaixa::class)
            ->callTableAction('reabrirFechamento', $fechamento, data: ['motivo' => 'Contagem errada'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatusFechamentoCaixa::Rascunho, $fechamento->fresh()->status);
        $this->assertSame(0.0, (float) $this->cofre->fresh()->saldo_atual);
        $this->assertSame(StatusLancamento::Cancelado, Lancamento::where('sessao_caixa_id', $sessao->id)->firstOrFail()->status);
    }

    public function test_reabrir_sem_motivo_com_contabilizacao_e_recusado(): void
    {
        $sessao = $this->sessaoComVenda();
        $fechamento = $this->rascunho($sessao);
        $this->confirmado($fechamento);
        app(ContabilizacaoFechamentoService::class)->contabilizar($sessao);

        Livewire::test(ListFechamentosCaixa::class)
            ->callTableAction('reabrirFechamento', $fechamento, data: [])
            ->assertHasTableActionErrors(['motivo']);

        $this->assertSame(StatusFechamentoCaixa::Confirmado, $fechamento->fresh()->status);
    }

    public function test_estornar_contabilizacao_e_depois_reabrir_funciona(): void
    {
        $sessao = $this->sessaoComVenda();
        $fechamento = $this->rascunho($sessao);
        $this->confirmado($fechamento);
        app(ContabilizacaoFechamentoService::class)->contabilizar($sessao);

        Livewire::test(ListFechamentosCaixa::class)
            ->callTableAction('estornarImportacaoReceber', $fechamento, data: ['motivo' => 'Contagem errada'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('reabrirFechamento', $fechamento)
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatusFechamentoCaixa::Rascunho, $fechamento->fresh()->status);
    }

    public function test_contabilizar_action_visivel_so_quando_confirmado_e_nao_contabilizado(): void
    {
        $sessao = $this->sessaoComVenda();
        $rascunho = $this->rascunho($sessao);

        $component = Livewire::test(ListFechamentosCaixa::class);
        $component->assertTableActionHidden('importarReceber', $rascunho);

        $this->confirmado($rascunho);
        $component = Livewire::test(ListFechamentosCaixa::class);
        $component->assertTableActionVisible('importarReceber', $rascunho->fresh());

        app(ContabilizacaoFechamentoService::class)->contabilizar($sessao);
        $component = Livewire::test(ListFechamentosCaixa::class);
        $component->assertTableActionHidden('importarReceber', $rascunho->fresh());
        $component->assertTableActionVisible('estornarImportacaoReceber', $rascunho->fresh());
    }
}
