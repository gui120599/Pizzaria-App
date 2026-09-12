<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Caixa;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\FechamentoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FechamentoCaixaServiceTest extends TestCase
{
    use RefreshDatabase;

    private FechamentoCaixaService $service;

    private SessaoCaixa $sessao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FechamentoCaixaService::class);
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 100,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    private function opcao(string $descNfe): OpcoesPagamento
    {
        return OpcoesPagamento::create([
            'opcaopag_nome' => $descNfe,
            'opcaopag_desc_nfe' => $descNfe,
        ]);
    }

    private function venda(string $status = 'FINALIZADA'): Venda
    {
        return Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_status' => $status,
            'venda_valor_total' => 0,
        ]);
    }

    private function pagamento(Venda $venda, OpcoesPagamento $opcao, float $valor): PagamentosVenda
    {
        return PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => $valor,
        ]);
    }

    public function test_calcula_esperado_agrupado_por_categoria(): void
    {
        $dinheiro = $this->opcao('cash');
        $debito = $this->opcao('debitCard');
        $credito = $this->opcao('creditCard');
        $pix = $this->opcao('InstantPayment');
        $outros = $this->opcao('foodVouchers');

        $venda = $this->venda();
        $this->pagamento($venda, $dinheiro, 100.0);
        $this->pagamento($venda, $debito, 50.0);
        $this->pagamento($venda, $credito, 30.0);
        $this->pagamento($venda, $pix, 20.0);
        $this->pagamento($venda, $outros, 15.0);

        $esperado = $this->service->calcularEsperado($this->sessao);

        $this->assertEqualsWithDelta(100.0, $esperado['dinheiro'], 0.01);
        $this->assertEqualsWithDelta(50.0, $esperado['debito'], 0.01);
        $this->assertEqualsWithDelta(30.0, $esperado['credito'], 0.01);
        $this->assertEqualsWithDelta(20.0, $esperado['pix'], 0.01);
        $this->assertEqualsWithDelta(15.0, $esperado['outros'], 0.01);
    }

    public function test_pagamento_stone_integrado_entra_na_categoria_credito(): void
    {
        $creditoStone = $this->opcao('creditCard');

        $venda = $this->venda();
        $pagamento = $this->pagamento($venda, $creditoStone, 80.0);
        $pagamento->update(['pg_venda_tipo_integracao' => 'integrated']);

        $esperado = $this->service->calcularEsperado($this->sessao);

        $this->assertEqualsWithDelta(80.0, $esperado['credito'], 0.01);
    }

    public function test_ignora_vendas_iniciadas_e_canceladas(): void
    {
        $dinheiro = $this->opcao('cash');

        $iniciada = $this->venda('INICIADA');
        $this->pagamento($iniciada, $dinheiro, 999.0);

        $cancelada = $this->venda('CANCELADA');
        $this->pagamento($cancelada, $dinheiro, 999.0);

        $finalizada = $this->venda('FINALIZADA');
        $this->pagamento($finalizada, $dinheiro, 42.0);

        $esperado = $this->service->calcularEsperado($this->sessao);

        $this->assertEqualsWithDelta(42.0, $esperado['dinheiro'], 0.01);
    }

    public function test_cria_rascunho_com_snapshot_do_esperado(): void
    {
        $dinheiro = $this->opcao('cash');
        $venda = $this->venda();
        $this->pagamento($venda, $dinheiro, 100.0);

        $fechamento = $this->service->criarOuAtualizarRascunho($this->sessao);

        $this->assertSame(StatusFechamentoCaixa::Rascunho, $fechamento->status);
        $this->assertEqualsWithDelta(100.0, (float) $fechamento->total_esperado_dinheiro, 0.01);
    }

    public function test_recalcular_atualiza_snapshot_enquanto_rascunho(): void
    {
        $dinheiro = $this->opcao('cash');
        $venda = $this->venda();
        $this->pagamento($venda, $dinheiro, 100.0);

        $fechamento = $this->service->criarOuAtualizarRascunho($this->sessao);

        $this->pagamento($venda, $dinheiro, 50.0);
        $fechamento = $this->service->criarOuAtualizarRascunho($this->sessao, $fechamento->fresh());

        $this->assertEqualsWithDelta(150.0, (float) $fechamento->total_esperado_dinheiro, 0.01);
    }

    public function test_nao_atualiza_snapshot_apos_confirmado(): void
    {
        $dinheiro = $this->opcao('cash');
        $venda = $this->venda();
        $this->pagamento($venda, $dinheiro, 100.0);

        $fechamento = $this->service->criarOuAtualizarRascunho($this->sessao);
        $this->service->confirmar($fechamento);

        $this->pagamento($venda, $dinheiro, 999.0);
        $fechamento = $this->service->criarOuAtualizarRascunho($this->sessao, $fechamento->fresh());

        $this->assertEqualsWithDelta(100.0, (float) $fechamento->total_esperado_dinheiro, 0.01);
    }

    private function lancamentoReceber(): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'descricao' => 'Fiado',
            'valor' => 1000,
            'vencimento' => now()->addDays(7),
            'status' => StatusLancamento::Pendente,
        ]);
    }

    public function test_recebimento_de_fiado_registrado_nesta_sessao_entra_no_esperado(): void
    {
        $lancamento = $this->lancamentoReceber();
        $lancamento->registrarPagamento(60, forma: FormaPagamento::Dinheiro, sessaoCaixaId: $this->sessao->id);
        $lancamento->registrarPagamento(25, forma: FormaPagamento::Pix, sessaoCaixaId: $this->sessao->id);

        $esperado = $this->service->calcularEsperado($this->sessao);

        $this->assertEqualsWithDelta(60.0, $esperado['dinheiro'], 0.01);
        $this->assertEqualsWithDelta(25.0, $esperado['pix'], 0.01);
    }

    public function test_pagamento_de_lancamento_sem_sessao_de_caixa_nao_entra_no_esperado(): void
    {
        $lancamento = $this->lancamentoReceber();
        // Registrado fora do PDV (ex.: tela de Contas a Receber) — sem sessão de caixa.
        $lancamento->registrarPagamento(60, forma: FormaPagamento::Dinheiro);

        $esperado = $this->service->calcularEsperado($this->sessao);

        $this->assertEqualsWithDelta(0.0, $esperado['dinheiro'], 0.01);
    }

    public function test_pagamento_de_titulo_a_pagar_nao_entra_no_esperado_mesmo_com_sessao(): void
    {
        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'descricao' => 'Fornecedor',
            'valor' => 1000,
            'vencimento' => now()->addDays(7),
            'status' => StatusLancamento::Pendente,
        ]);
        $lancamento->registrarPagamento(60, forma: FormaPagamento::Dinheiro, sessaoCaixaId: $this->sessao->id);

        $esperado = $this->service->calcularEsperado($this->sessao);

        $this->assertEqualsWithDelta(0.0, $esperado['dinheiro'], 0.01);
    }

    public function test_sangria_reduz_esperado_na_forma_correta(): void
    {
        $dinheiro = $this->opcao('cash');
        $venda = $this->venda();
        $this->pagamento($venda, $dinheiro, 100.0);

        // A sessão deste teste já está FECHADA (setUp) — registra o movimento
        // manual direto, sem passar por MovimentacaoCaixaService::registrarSaida()
        // (que exige sessão ABERTA; isso é coberto em MovimentacaoCaixaServiceTest).
        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $this->sessao->id,
            'mov_descricao' => 'Sangria de teste',
            'mov_tipo' => 'SAIDA',
            'mov_forma_pagamento' => FormaPagamento::Dinheiro,
            'mov_motivo' => MotivoSaidaCaixa::Sangria,
            'mov_valor' => 30.0,
        ]);

        $esperado = $this->service->calcularEsperado($this->sessao);

        $this->assertEqualsWithDelta(70.0, $esperado['dinheiro'], 0.01);
    }

    /** Saída legada (sem mov_motivo, registrada antes da coluna existir) não é descontada — não muda retroativamente conferências já confirmadas. */
    public function test_saida_legada_sem_motivo_nao_afeta_esperado(): void
    {
        $dinheiro = $this->opcao('cash');
        $venda = $this->venda();
        $this->pagamento($venda, $dinheiro, 100.0);

        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $this->sessao->id,
            'mov_descricao' => 'Saída antiga (fluxo Blade legado)',
            'mov_tipo' => 'SAIDA',
            'mov_valor' => 40.0,
        ]);

        $esperado = $this->service->calcularEsperado($this->sessao);

        $this->assertEqualsWithDelta(100.0, $esperado['dinheiro'], 0.01);
    }

    /** SAIDA compensatória da Stone (mov_venda_id preenchido, sem mov_motivo) não é descontada de novo — o efeito já vem da queda de venda_valor_pago. */
    public function test_saida_da_stone_nao_e_descontada_do_esperado(): void
    {
        $credito = $this->opcao('creditCard');
        $venda = $this->venda();
        $this->pagamento($venda, $credito, 80.0);

        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $this->sessao->id,
            'mov_venda_id' => $venda->id,
            'mov_descricao' => 'ESTORNO STONE: venda '.$venda->id,
            'mov_tipo' => 'SAIDA',
            'mov_valor' => 80.0,
        ]);

        $esperado = $this->service->calcularEsperado($this->sessao);

        $this->assertEqualsWithDelta(80.0, $esperado['credito'], 0.01);
    }

    public function test_calcula_receita_vendas_por_opcao_de_pagamento(): void
    {
        $dinheiro = $this->opcao('cash');
        $pix = $this->opcao('InstantPayment');
        $venda = $this->venda();
        $this->pagamento($venda, $dinheiro, 70.0);
        $this->pagamento($venda, $pix, 30.0);

        $linhas = $this->service->calcularReceitaVendas($this->sessao)->keyBy('opcaopagamento_id');

        $this->assertEqualsWithDelta(70.0, (float) $linhas[$dinheiro->id]->total, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $linhas[$pix->id]->total, 0.01);
    }

    /** Fonte da importação pro Contas a Receber: sem troco de abertura, sem recebimento de fiado. */
    public function test_receita_vendas_nao_inclui_saldo_inicial_nem_fiado(): void
    {
        $dinheiro = $this->opcao('cash');
        $venda = $this->venda();
        $this->pagamento($venda, $dinheiro, 100.0);

        // Saldo inicial de abertura (mov_motivo nulo).
        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $this->sessao->id,
            'mov_descricao' => 'Saldo inicial de abertura',
            'mov_tipo' => 'ENTRADA',
            'mov_forma_pagamento' => FormaPagamento::Dinheiro,
            'mov_valor' => 200.0,
        ]);

        // Recebimento de fiado nesta sessão (já é pagamento de um Lancamento próprio).
        $fiado = $this->lancamentoReceber();
        $fiado->registrarPagamento(60, forma: FormaPagamento::Dinheiro, sessaoCaixaId: $this->sessao->id);

        $totalReceita = $this->service->calcularReceitaVendas($this->sessao)->sum('total');

        $this->assertEqualsWithDelta(100.0, $totalReceita, 0.01);
    }

    public function test_confirmar_e_reabrir_alteram_status(): void
    {
        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $this->sessao->id,
            'user_id' => $this->sessao->sessaocaixa_user_id,
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        $this->service->confirmar($fechamento);
        $this->assertSame(StatusFechamentoCaixa::Confirmado, $fechamento->fresh()->status);
        $this->assertNotNull($fechamento->fresh()->confirmado_em);

        $this->service->reabrir($fechamento);
        $this->assertSame(StatusFechamentoCaixa::Rascunho, $fechamento->fresh()->status);
        $this->assertNull($fechamento->fresh()->confirmado_em);
    }
}
