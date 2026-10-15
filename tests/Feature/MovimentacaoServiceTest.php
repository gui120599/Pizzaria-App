<?php

namespace Tests\Feature;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Exceptions\FinanceiroException;
use App\Models\Caixa;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\FechamentoCaixaService;
use App\Services\Financeiro\MovimentacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MovimentacaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($this->admin);
    }

    public function test_saida_do_cofre_baixa_o_saldo_sem_tocar_na_sessao_de_caixa(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->comSaldo(500)->create();
        $sessao = $this->sessaoAberta(200);

        $movimentacao = $this->servico()->saida($cofre, 120, NaturezaMovimentacao::PagamentoLancamento, now(), 'Gás', [], $this->admin);

        $this->assertSame(TipoMovimentacao::Saida, $movimentacao->tipo);
        $this->assertEquals(380, $cofre->fresh()->saldo_atual);
        $this->assertSame(0, MovimentacoesSessaoCaixa::query()->where('mov_sessaocaixa_id', $sessao->id)->manuais()->count());
    }

    public function test_saldo_insuficiente_bloqueia_a_saida(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->comSaldo(50)->create();

        $this->expectException(FinanceiroException::class);

        $this->servico()->saida($cofre, 80, NaturezaMovimentacao::PagamentoLancamento, now(), 'Gás', [], $this->admin);
    }

    public function test_conta_que_permite_negativo_aceita_a_saida(): void
    {
        $banco = ContaFinanceira::factory()->banco()->permiteNegativo()->comSaldo(50)->create();

        $this->servico()->saida($banco, 80, NaturezaMovimentacao::PagamentoLancamento, now(), 'Aluguel', [], $this->admin);

        $this->assertEquals(-30, $banco->fresh()->saldo_atual);
    }

    public function test_transferencia_do_caixa_pdv_para_o_cofre_vira_sangria_ligada_na_sessao(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $cofre = ContaFinanceira::factory()->cofre()->create();
        $sessao = $this->sessaoAberta(300);

        $pernas = $this->servico()->transferir($pdv, $cofre, 200, now(), null, $this->admin);

        $this->assertSame($pernas['saida']->transferencia_uuid, $pernas['entrada']->transferencia_uuid);
        $this->assertEquals(200, $cofre->fresh()->saldo_atual);
        $sangria = MovimentacoesSessaoCaixa::query()->where('movimentacao_financeira_id', $pernas['saida']->id)->sole();
        $this->assertSame(MotivoSaidaCaixa::Sangria, $sangria->mov_motivo);
        $this->assertSame($sessao->id, $sangria->mov_sessaocaixa_id);
        $this->assertEqualsWithDelta(100, app(FechamentoCaixaService::class)->calcularEsperado($sessao)['dinheiro'], 0.001);
        $this->assertSame(0, Lancamento::count());
    }

    public function test_saida_do_caixa_pdv_sem_sessao_aberta_e_recusada(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $cofre = ContaFinanceira::factory()->cofre()->create();

        try {
            $this->servico()->transferir($pdv, $cofre, 50, now(), null, $this->admin);
            $this->fail('Saída do Caixa PDV sem sessão aberta foi aceita.');
        } catch (FinanceiroException) {
        }

        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_saida_do_caixa_pdv_acima_do_dinheiro_da_sessao_e_recusada(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $this->sessaoAberta(100);

        $this->expectException(FinanceiroException::class);

        $this->servico()->saida($pdv, 150, NaturezaMovimentacao::PagamentoLancamento, now(), 'Gás', [], $this->admin);
    }

    public function test_com_duas_sessoes_abertas_a_saida_do_caixa_pdv_exige_escolher_a_sessao(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $this->sessaoAberta(100);
        $escolhida = $this->sessaoAberta(100);

        try {
            $this->servico()->saida($pdv, 30, NaturezaMovimentacao::PagamentoLancamento, now(), 'Gás', [], $this->admin);
            $this->fail('Saída sem sessão escolhida foi aceita com duas sessões abertas.');
        } catch (FinanceiroException) {
        }

        $movimentacao = $this->servico()->saida($pdv, 30, NaturezaMovimentacao::PagamentoLancamento, now(), 'Gás', [], $this->admin, $escolhida);

        $this->assertSame($escolhida->id, $movimentacao->sessao_caixa_id);
    }

    public function test_estorno_de_transferencia_estorna_as_duas_pontas_e_tira_a_sangria_da_sessao(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $cofre = ContaFinanceira::factory()->cofre()->create();
        $sessao = $this->sessaoAberta(300);
        $pernas = $this->servico()->transferir($pdv, $cofre, 200, now(), null, $this->admin);

        $estornos = $this->servico()->estornar($pernas['entrada'], 'Transferência lançada errada', $this->admin);

        $this->assertCount(2, $estornos);
        $this->assertEquals(0, $cofre->fresh()->saldo_atual);
        $this->assertSoftDeleted(MovimentacoesSessaoCaixa::withTrashed()->where('movimentacao_financeira_id', $pernas['saida']->id)->sole());
        $this->assertEqualsWithDelta(300, app(FechamentoCaixaService::class)->calcularEsperado($sessao)['dinheiro'], 0.001);
        $this->assertSame(0, MovimentacaoFinanceira::query()->efetivas()->count());
    }

    public function test_estorno_de_saida_do_caixa_pdv_com_sessao_fechada_e_recusado(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $sessao = $this->sessaoAberta(300);
        $saida = $this->servico()->saida($pdv, 50, NaturezaMovimentacao::PagamentoLancamento, now(), 'Gás', [], $this->admin);
        $sessao->update(['sessaocaixa_status' => 'FECHADA']);

        $this->expectException(FinanceiroException::class);

        $this->servico()->estornar($saida, 'Lançado em duplicidade', $this->admin);
    }

    public function test_estorno_nao_pode_ser_estornado_nem_repetido(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->comSaldo(100)->create();
        $saida = $this->servico()->saida($cofre, 10, NaturezaMovimentacao::PagamentoLancamento, now(), 'Gás', [], $this->admin);
        $estorno = $this->servico()->estornar($saida, 'Lançado errado', $this->admin)->sole();

        foreach ([$saida, $estorno] as $movimentacao) {
            try {
                $this->servico()->estornar($movimentacao, 'Lançado errado', $this->admin);
                $this->fail('Estornou duas vezes.');
            } catch (FinanceiroException) {
            }
        }

        $this->assertEquals(100, $cofre->fresh()->saldo_atual);
    }

    public function test_ajuste_de_saldo_grava_a_diferenca(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->comSaldo(100)->create();

        $ajuste = $this->servico()->ajustarSaldo($cofre, 92.5, 'Contagem do cofre', $this->admin);

        $this->assertSame(TipoMovimentacao::Saida, $ajuste->tipo);
        $this->assertEquals(7.5, $ajuste->valor);
        $this->assertEquals(92.5, $cofre->fresh()->saldo_atual);
    }

    public function test_recalcular_saldos_reconstroi_o_cache_a_partir_das_movimentacoes(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->comSaldo(100)->create();
        $this->servico()->saida($cofre, 30, NaturezaMovimentacao::PagamentoLancamento, now(), 'Gás', [], $this->admin);
        DB::table('contas_financeiras')->where('id', $cofre->id)->update(['saldo_atual' => 999]);

        $this->artisan('financeiro:recalcular-saldos')->assertSuccessful();

        $this->assertEquals(70, $cofre->fresh()->saldo_atual);
    }

    /** Sessão aberta com o troco inicial em dinheiro (movimento de abertura, como o SessaoCaixaService grava). */
    private function sessaoAberta(float $dinheiro): SessaoCaixa
    {
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa '.fake()->unique()->numberBetween(1, 999)])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHour(),
            'sessaocaixa_saldo_inicial' => $dinheiro,
            'sessaocaixa_saldo_final' => $dinheiro,
            'sessaocaixa_user_id' => $this->admin->id,
        ]);

        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $sessao->id,
            'mov_descricao' => 'Abertura',
            'mov_tipo' => 'ENTRADA',
            'mov_forma_pagamento' => FormaPagamento::Dinheiro,
            'mov_user_id' => $this->admin->id,
            'mov_valor' => $dinheiro,
        ]);

        return $sessao;
    }

    private function servico(): MovimentacaoService
    {
        return app(MovimentacaoService::class);
    }
}
