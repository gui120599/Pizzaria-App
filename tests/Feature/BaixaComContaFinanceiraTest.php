<?php

namespace Tests\Feature;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\Caixa;
use App\Models\ContaFinanceira;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\LancamentoPagamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\PlanoReceita;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\FechamentoCaixaService;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use App\Services\Financeiro\MovimentacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaixaComContaFinanceiraTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($this->admin);
    }

    public function test_baixa_com_conta_gera_a_saida_na_conta(): void
    {
        $banco = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();
        $lancamento = $this->lancamento();

        $pagamento = $lancamento->registrarPagamento(100, forma: FormaPagamento::Pix, contaFinanceiraId: $banco->id);

        $movimentacao = MovimentacaoFinanceira::sole();
        $this->assertSame(TipoMovimentacao::Saida, $movimentacao->tipo);
        $this->assertSame(NaturezaMovimentacao::PagamentoLancamento, $movimentacao->natureza);
        $this->assertSame($pagamento->id, $movimentacao->lancamento_pagamento_id);
        $this->assertSame($lancamento->id, $movimentacao->lancamento_id);
        $this->assertEquals(900, $banco->fresh()->saldo_atual);
        $this->assertSame(StatusLancamento::Pago, $lancamento->fresh()->status);
    }

    public function test_recebimento_com_conta_gera_entrada(): void
    {
        $banco = ContaFinanceira::factory()->banco()->create();

        $this->lancamento(['tipo' => TipoLancamento::Receber])->registrarPagamento(100, forma: FormaPagamento::Pix, contaFinanceiraId: $banco->id);

        $this->assertSame(TipoMovimentacao::Entrada, MovimentacaoFinanceira::sole()->tipo);
        $this->assertEquals(100, $banco->fresh()->saldo_atual);
    }

    public function test_baixa_sem_conta_nao_movimenta(): void
    {
        $this->lancamento()->registrarPagamento(100, forma: FormaPagamento::Pix);

        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_compensacao_ignora_a_conta(): void
    {
        $banco = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();

        $pagamento = $this->lancamento()->registrarPagamento(100, forma: FormaPagamento::Compensacao, contaFinanceiraId: $banco->id);

        $this->assertNull($pagamento->conta_financeira_id);
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_saldo_insuficiente_nao_grava_o_pagamento(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->comSaldo(50)->create();
        $lancamento = $this->lancamento();

        try {
            $lancamento->registrarPagamento(100, forma: FormaPagamento::Dinheiro, contaFinanceiraId: $cofre->id);
            $this->fail('Pagamento acima do saldo foi gravado.');
        } catch (FinanceiroException) {
        }

        $this->assertSame(0, LancamentoPagamento::count());
        $this->assertSame(StatusLancamento::Pendente, $lancamento->fresh()->status);
    }

    public function test_estornar_pagamento_devolve_o_dinheiro_e_reabre_o_titulo(): void
    {
        $banco = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();
        $lancamento = $this->lancamento();
        $lancamento->registrarPagamento(100, forma: FormaPagamento::Pix, contaFinanceiraId: $banco->id);

        $lancamento->fresh()->estornarPagamento('Pago na conta errada');

        $this->assertSame(StatusLancamento::Pendente, $lancamento->fresh()->status);
        $this->assertEquals(1000, $banco->fresh()->saldo_atual);
        $estorno = MovimentacaoFinanceira::query()->whereNotNull('estorno_de_id')->sole();
        $this->assertSame(TipoMovimentacao::Entrada, $estorno->tipo);
        $this->assertStringContainsString('Pago na conta errada', $estorno->descricao);
    }

    public function test_estornar_a_movimentacao_da_baixa_exclui_o_pagamento_e_reabre_o_titulo(): void
    {
        $banco = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();
        $lancamento = $this->lancamento();
        $lancamento->registrarPagamento(100, forma: FormaPagamento::Pix, contaFinanceiraId: $banco->id);

        app(MovimentacaoService::class)->estornar(MovimentacaoFinanceira::sole(), 'Pago na conta errada', $this->admin);

        $this->assertSame(0, LancamentoPagamento::count());
        $this->assertSame(StatusLancamento::Pendente, $lancamento->fresh()->status);
        $this->assertEquals(1000, $banco->fresh()->saldo_atual);
    }

    public function test_pagamento_que_movimentou_a_conta_nao_muda_de_valor(): void
    {
        $banco = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();
        $pagamento = $this->lancamento()->registrarPagamento(100, forma: FormaPagamento::Pix, contaFinanceiraId: $banco->id);

        $this->expectException(FinanceiroException::class);

        $pagamento->update(['valor' => 80]);
    }

    public function test_pagamento_antigo_nao_ganha_conta_depois(): void
    {
        $banco = ContaFinanceira::factory()->banco()->create();
        $pagamento = $this->lancamento()->registrarPagamento(100, forma: FormaPagamento::Pix);

        $this->expectException(FinanceiroException::class);

        $pagamento->update(['conta_financeira_id' => $banco->id]);
    }

    public function test_forma_de_pagamento_pode_ser_corrigida_depois_da_movimentacao(): void
    {
        $banco = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();
        $pagamento = $this->lancamento()->registrarPagamento(100, forma: FormaPagamento::Pix, contaFinanceiraId: $banco->id);

        $pagamento->update(['forma_pagamento' => FormaPagamento::Transferencia]);

        $this->assertSame(FormaPagamento::Transferencia, $pagamento->fresh()->forma_pagamento);
    }

    public function test_lancamento_que_movimentou_conta_nao_e_excluido(): void
    {
        $banco = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();
        $lancamento = $this->lancamento();
        $lancamento->registrarPagamento(100, forma: FormaPagamento::Pix, contaFinanceiraId: $banco->id);
        $lancamento->fresh()->estornarPagamento('Pago na conta errada');

        $this->expectException(FinanceiroException::class);

        $lancamento->fresh()->delete();
    }

    public function test_baixa_pelo_caixa_pdv_registra_retirada_ligada_na_sessao_aberta(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $sessao = $this->sessao('ABERTA', 300);

        $pagamento = $this->lancamento()->registrarPagamento(100, forma: FormaPagamento::Dinheiro, contaFinanceiraId: $pdv->id);

        $this->assertSame($sessao->id, $pagamento->sessao_caixa_id);
        $retirada = MovimentacoesSessaoCaixa::query()->whereNotNull('movimentacao_financeira_id')->sole();
        $this->assertSame(MotivoSaidaCaixa::PagamentoDespesa, $retirada->mov_motivo);
        $this->assertSame(MovimentacaoFinanceira::sole()->id, $retirada->movimentacao_financeira_id);
        $this->assertEqualsWithDelta(200, app(FechamentoCaixaService::class)->calcularEsperado($sessao)['dinheiro'], 0.001);
    }

    public function test_estornar_baixa_do_caixa_pdv_devolve_o_dinheiro_a_sessao(): void
    {
        ContaFinanceira::factory()->caixaPdv()->create();
        $sessao = $this->sessao('ABERTA', 300);
        $lancamento = $this->lancamento();
        $lancamento->registrarPagamento(100, forma: FormaPagamento::Dinheiro, contaFinanceiraId: ContaFinanceira::caixaPdv()->id);

        $lancamento->fresh()->estornarPagamento('Pago na conta errada');

        $this->assertEqualsWithDelta(300, app(FechamentoCaixaService::class)->calcularEsperado($sessao)['dinheiro'], 0.001);
    }

    public function test_importacao_de_sessao_nao_duplica_despesa_paga_pelo_caixa_pdv(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $sessao = $this->sessao('ABERTA', 300);
        $this->lancamento(['descricao' => 'Gás'])->registrarPagamento(100, forma: FormaPagamento::Dinheiro, contaFinanceiraId: $pdv->id);
        $venda = Venda::create(['venda_sessao_caixa_id' => $sessao->id, 'venda_status' => 'FINALIZADA', 'venda_valor_total' => 0]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash', 'plano_receita_id' => PlanoReceita::create(['nome' => 'Balcão'])->id])->id,
            'pg_venda_valor_pagamento' => 80,
        ]);
        $sessao->update(['sessaocaixa_status' => 'FECHADA']);
        FechamentoCaixa::create(['sessao_caixa_id' => $sessao->id, 'user_id' => $this->admin->id, 'status' => StatusFechamentoCaixa::Confirmado, 'confirmado_em' => now()]);

        app(ContabilizacaoFechamentoService::class)->contabilizar($sessao);

        $this->assertSame(1, Lancamento::query()->where('tipo', TipoLancamento::Pagar)->count());
        // A despesa paga pela gaveta e a entrada das vendas em dinheiro: o título da despesa não é recriado.
        $this->assertSame(2, MovimentacaoFinanceira::count());
    }

    /** @param  array<string, mixed>  $atributos */
    private function lancamento(array $atributos = []): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'descricao' => 'Título',
            'valor' => 100,
            'vencimento' => today()->toDateString(),
            'status' => StatusLancamento::Pendente,
            ...$atributos,
        ]);
    }

    private function sessao(string $status, float $dinheiro): SessaoCaixa
    {
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => $status,
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
}
