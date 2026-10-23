<?php

namespace Tests\Feature;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Enums\FormaTrocoEnum;
use App\Enums\OperadoraMaquininha;
use App\Enums\PrazoRecebimentoMaquininhaEnum;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Enums\TipoNotaMoeda;
use App\Enums\TipoPagamentoMaquininhaEnum;
use App\Exceptions\FinanceiroException;
use App\Models\Caixa;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\Maquininha;
use App\Models\MaquininhaTaxa;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\NotaMoeda;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\FechamentoCaixaService;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use App\Services\Financeiro\MovimentacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Contabilização do fechamento nas contas financeiras: dinheiro no Caixa PDV,
 * PIX CNPJ na conta do PIX, cartão/Pix de maquininha como recebível pendente,
 * quebra, troco e recolhimento.
 */
class ContabilizacaoFechamentoContasTest extends TestCase
{
    use RefreshDatabase;

    private ContabilizacaoFechamentoService $service;

    private SessaoCaixa $sessao;

    private ContaFinanceira $caixaPdv;

    private ContaFinanceira $cofre;

    private ContaFinanceira $contaPix;

    private ContaFinanceira $contaStone;

    private PlanoReceita $planoReceita;

    private Maquininha $maquininha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->service = app(ContabilizacaoFechamentoService::class);

        $this->caixaPdv = ContaFinanceira::factory()->caixaPdv()->comSaldo(100)->create();
        $this->cofre = ContaFinanceira::factory()->cofre()->create();
        $this->contaPix = ContaFinanceira::factory()->banco()->create(['nome' => 'Banco PIX']);
        $this->contaStone = ContaFinanceira::factory()->banco()->create(['nome' => 'Conta Stone']);
        $this->planoReceita = PlanoReceita::create(['nome' => 'Balcão']);

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_plano_despesa_taxa_cartao_id' => PlanoDespesa::create(['nome' => 'Taxas de cartão', 'comportamento' => 'variavel', 'periodicidade' => 'mensal'])->id,
            'empresa_plano_despesa_quebra_caixa_id' => PlanoDespesa::create(['nome' => 'Quebra de caixa', 'comportamento' => 'variavel', 'periodicidade' => 'eventual'])->id,
            'empresa_plano_receita_sobra_caixa_id' => PlanoReceita::create(['nome' => 'Sobra de caixa'])->id,
        ]);

        $this->maquininha = Maquininha::create([
            'nome' => 'Stone 1',
            'operadora' => OperadoraMaquininha::Stone,
            'maquininha_padrao' => true,
            'conta_financeira_id' => $this->contaStone->id,
        ]);
        MaquininhaTaxa::create(['mt_maquininha_id' => $this->maquininha->id, 'mt_tipo' => TipoPagamentoMaquininhaEnum::Debito, 'mt_percentual' => 1.5, 'mt_prazo_recebimento' => PrazoRecebimentoMaquininhaEnum::NaHora]);
        MaquininhaTaxa::create(['mt_maquininha_id' => $this->maquininha->id, 'mt_tipo' => TipoPagamentoMaquininhaEnum::Credito, 'mt_percentual' => 3, 'mt_prazo_recebimento' => PrazoRecebimentoMaquininhaEnum::ProximoDiaUtil]);

        $this->sessao = $this->novaSessao(100);
    }

    private function novaSessao(float $saldoInicial, ?Caixa $caixa = null, string $abertura = '08:00'): SessaoCaixa
    {
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => ($caixa ?? Caixa::create(['caixa_nome' => 'Caixa 1']))->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => today()->setTimeFromTimeString($abertura),
            'sessaocaixa_data_hora_fechamento' => today()->setTimeFromTimeString('18:00'),
            'sessaocaixa_saldo_inicial' => $saldoInicial,
            'sessaocaixa_saldo_final' => $saldoInicial,
            'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Operador'])->id,
        ]);

        if ($saldoInicial > 0) {
            MovimentacoesSessaoCaixa::create([
                'mov_sessaocaixa_id' => $sessao->id,
                'mov_descricao' => 'Saldo inicial de abertura',
                'mov_tipo' => 'ENTRADA',
                'mov_forma_pagamento' => 'dinheiro',
                'mov_valor' => $saldoInicial,
            ]);
        }

        return $sessao;
    }

    /** @param  array<string, mixed>  $pagamento */
    private function vender(string $descNfe, float $valor, bool $pixCnpj = false, ?int $contaId = null, array $pagamento = [], ?SessaoCaixa $sessao = null): OpcoesPagamento
    {
        $opcao = OpcoesPagamento::firstOrCreate(
            ['opcaopag_nome' => "Forma {$descNfe}".($pixCnpj ? ' CNPJ' : '')],
            [
                'opcaopag_desc_nfe' => $descNfe,
                'opcaopag_pix_cnpj' => $pixCnpj,
                'plano_receita_id' => $this->planoReceita->id,
                'conta_financeira_id' => $contaId,
            ],
        );

        $venda = Venda::create([
            'venda_sessao_caixa_id' => ($sessao ?? $this->sessao)->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => $valor,
            'venda_datahora_finalizada' => today()->setTimeFromTimeString('12:00'),
        ]);

        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => $valor,
            ...$pagamento,
        ]);

        return $opcao;
    }

    /** Vendas padrão: dinheiro 100, PIX CNPJ 50, débito 80 e crédito 60. */
    private function vendasPadrao(?SessaoCaixa $sessao = null): void
    {
        $this->vender('cash', 100, sessao: $sessao);
        $this->vender('InstantPayment', 50, pixCnpj: true, contaId: $this->contaPix->id, sessao: $sessao);
        $this->vender('debitCard', 80, sessao: $sessao);
        $this->vender('creditCard', 60, sessao: $sessao);
    }

    private function fechar(float $contado, ?float $troco = null, ?SessaoCaixa $sessao = null, ?ContaFinanceira $recolherPara = null): FechamentoCaixa
    {
        $sessao ??= $this->sessao;
        $nota = NotaMoeda::firstOrCreate(['descricao' => 'Real'], ['valor' => 1, 'tipo' => TipoNotaMoeda::Cedula, 'ordem_exibicao' => 1]);

        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);
        $fechamento->notas()->create(['nota_moeda_id' => $nota->id, 'quantidade' => (int) $contado]);
        app(FechamentoCaixaService::class)->criarOuAtualizarRascunho($sessao, $fechamento);

        $fechamento->update([
            'valor_troco_gaveta' => $troco,
            'recolhimento_conta_id' => $recolherPara?->id ?? ($troco !== null ? $this->cofre->id : null),
            'status' => StatusFechamentoCaixa::Confirmado,
            'confirmado_em' => now(),
        ]);

        return $fechamento->refresh();
    }

    private function saldo(ContaFinanceira $conta): float
    {
        return (float) $conta->fresh()->saldo_atual;
    }

    public function test_dinheiro_pix_e_recebiveis_vao_para_as_contas_certas(): void
    {
        $this->vendasPadrao();
        $this->fechar(contado: 200, troco: 100);

        $lancamentos = $this->service->contabilizar($this->sessao);

        $this->assertSame(100.0, $this->saldo($this->caixaPdv));
        $this->assertSame(50.0, $this->saldo($this->contaPix));
        $this->assertSame(100.0, $this->saldo($this->cofre));
        $this->assertSame(0.0, $this->saldo($this->contaStone));

        $recebiveis = $lancamentos->whereNotNull('maquininha_id');
        $this->assertCount(2, $recebiveis);
        $this->assertSame([StatusLancamento::Pendente], $recebiveis->pluck('status')->unique()->values()->all());
        $this->assertSame(0, MovimentacaoFinanceira::where('conta_financeira_id', $this->contaStone->id)->count());
        $this->assertSame(0, MovimentacaoFinanceira::where('data_movimento', '>', today()->toDateString())->count());

        $debito = $recebiveis->firstWhere('valor', '80.00');
        $this->assertSame(today()->toDateString(), $debito->vencimento->toDateString());
        $this->assertEqualsWithDelta(1.2, (float) $debito->taxa_adquirente, 0.001);
        $credito = $recebiveis->firstWhere('valor', '60.00');
        $this->assertTrue($credito->vencimento->gt(today()));
    }

    public function test_esperado_da_sessao_nao_muda_e_nada_grava_sessao_caixa_id_no_pagamento(): void
    {
        $this->vendasPadrao();
        $this->fechar(contado: 200, troco: 100);
        $antes = app(FechamentoCaixaService::class)->calcularEsperado($this->sessao);

        $this->service->contabilizar($this->sessao);

        $this->assertSame($antes, app(FechamentoCaixaService::class)->calcularEsperado($this->sessao));
        $this->assertSame(0, \DB::table('lancamento_pagamentos')->whereNotNull('sessao_caixa_id')->count());
    }

    public function test_contabilizar_a_sessao_fechada_nao_toca_a_sessao_aberta(): void
    {
        $aberta = $this->novaSessao(0);
        $aberta->update(['sessaocaixa_status' => 'ABERTA']);
        $esperadoAberta = app(FechamentoCaixaService::class)->calcularEsperado($aberta);
        $this->vendasPadrao();
        $this->fechar(contado: 200, troco: 100);

        $this->service->contabilizar($this->sessao);

        $this->assertSame($esperadoAberta, app(FechamentoCaixaService::class)->calcularEsperado($aberta));
        $this->assertSame(0, MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $aberta->id)->count());
        $this->assertSame(0, MovimentacaoFinanceira::where('sessao_caixa_id', $aberta->id)->count());
    }

    public function test_quebra_de_caixa_gera_despesa_paga_pelo_caixa_pdv(): void
    {
        $this->vender('cash', 100);
        $this->fechar(contado: 190, troco: 100);

        $lancamentos = $this->service->contabilizar($this->sessao);

        $quebra = $lancamentos->firstWhere('tipo', TipoLancamento::Pagar);
        $this->assertEqualsWithDelta(10.0, (float) $quebra->valor, 0.001);
        $this->assertSame(StatusLancamento::Pago, $quebra->status);
        $this->assertSame(100.0, $this->saldo($this->caixaPdv));
        $this->assertSame(90.0, $this->saldo($this->cofre));
    }

    public function test_sobra_de_caixa_gera_receita_no_caixa_pdv(): void
    {
        $this->vender('cash', 100);
        $this->fechar(contado: 205, troco: 100);

        $lancamentos = $this->service->contabilizar($this->sessao);

        $sobra = $lancamentos->firstWhere('tipo', TipoLancamento::Receber);
        $this->assertNotNull($sobra->plano_receita_id);
        $this->assertSame(100.0, $this->saldo($this->caixaPdv));
        $this->assertSame(105.0, $this->saldo($this->cofre));
    }

    public function test_recolhimento_guarda_uuid_e_nao_cria_linha_na_sessao_fechada(): void
    {
        $this->vender('cash', 100);
        $fechamento = $this->fechar(contado: 200, troco: 40);

        $this->service->contabilizar($this->sessao);

        $fechamento->refresh();
        $this->assertEqualsWithDelta(160.0, (float) $fechamento->valor_recolhido, 0.001);
        $this->assertNotNull($fechamento->recolhimento_transferencia_uuid);
        $this->assertSame(2, MovimentacaoFinanceira::where('transferencia_uuid', $fechamento->recolhimento_transferencia_uuid)->count());
        $this->assertSame(1, MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $this->sessao->id)->count());
        $this->assertSame(40.0, $this->saldo($this->caixaPdv));
    }

    public function test_troco_maior_que_o_contado_e_recusado_e_nada_e_gravado(): void
    {
        $this->vender('cash', 100);
        $this->fechar(contado: 200, troco: 250);

        try {
            $this->service->contabilizar($this->sessao);
            $this->fail('Deveria recusar o troco.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('valor_troco_gaveta', $e->errors());
        }

        $this->assertSame(0, Lancamento::count());
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_identidade_do_caixa_pdv_em_duas_sessoes_seguidas_do_mesmo_caixa(): void
    {
        $this->vender('cash', 100);
        $this->fechar(contado: 200, troco: 150);
        $this->service->contabilizar($this->sessao);
        $this->assertSame(150.0, $this->saldo($this->caixaPdv));

        $segunda = $this->novaSessao(150, $this->sessao->caixa);
        $this->vender('cash', 70, sessao: $segunda);
        $this->fechar(contado: 220, troco: 120, sessao: $segunda);
        $this->service->contabilizar($segunda);

        $this->assertSame(120.0, $this->saldo($this->caixaPdv));
        $this->assertSame(150.0, $this->saldo($this->cofre));
    }

    public function test_conferencia_do_caixa_pdv_que_nao_fecha_aborta_tudo(): void
    {
        $this->vender('cash', 100);
        // Entrada no razão ligada à sessão que o esperado desconhece (não veio de venda, suprimento nem fiado).
        app(MovimentacaoService::class)->entrada(
            $this->caixaPdv, 50, NaturezaMovimentacao::AjusteSaldo, today(), 'Lançamento solto', [], registrarNaSessao: false, sessaoCaixaId: $this->sessao->id,
        );
        $this->fechar(contado: 200, troco: 100);

        try {
            $this->service->contabilizar($this->sessao);
            $this->fail('Deveria abortar.');
        } catch (FinanceiroException $e) {
            $this->assertStringContainsString('conferência do Caixa PDV', $e->getMessage());
        }

        $this->assertSame(0, Lancamento::count());
        $this->assertSame(1, MovimentacaoFinanceira::count());
    }

    public function test_troco_em_pix_de_venda_em_dinheiro_sai_da_conta_pix_para_a_gaveta(): void
    {
        $this->vender('InstantPayment', 10, pixCnpj: true, contaId: $this->contaPix->id);
        $this->contaPix->update(['saldo_inicial' => 50, 'saldo_atual' => 50]);
        // Venda de 95,70 paga com 100 em dinheiro e troco de 4,30 em Pix.
        $this->vender('cash', 95.70, pagamento: ['pg_venda_valor_troco' => 4.30, 'pg_venda_forma_troco' => FormaTrocoEnum::Pix]);
        $this->fechar(contado: 204.30, troco: 100);

        $this->service->contabilizar($this->sessao);

        $this->assertSame(100.0, $this->saldo($this->caixaPdv));
        $this->assertEqualsWithDelta(50 + 10 - 4.30, $this->saldo($this->contaPix), 0.001);
    }

    public function test_contabilizar_duas_vezes_nao_duplica(): void
    {
        $this->vendasPadrao();
        $this->fechar(contado: 200, troco: 100);

        $primeira = $this->service->contabilizar($this->sessao);
        $movimentacoes = MovimentacaoFinanceira::count();
        $segunda = $this->service->contabilizar($this->sessao);

        $this->assertSame($primeira->count(), $segunda->count());
        $this->assertSame($movimentacoes, MovimentacaoFinanceira::count());
        $this->assertFalse($this->service->podeContabilizar($this->sessao));
    }

    public function test_configuracao_faltando_bloqueia_sem_gravar(): void
    {
        $this->vendasPadrao();
        $this->maquininha->update(['conta_financeira_id' => null]);
        $this->fechar(contado: 200, troco: 100);

        try {
            $this->service->contabilizar($this->sessao);
            $this->fail('Deveria bloquear.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stone 1', collect($e->errors())->flatten()->first());
        }

        $this->assertSame(0, Lancamento::count());
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_recebivel_com_repasse_antes_do_saldo_inicial_da_conta_nao_gera_titulo(): void
    {
        $this->contaStone->update(['data_saldo_inicial' => today()->addDay()]);
        $this->vender('debitCard', 80);
        $this->vender('cash', 10);
        $this->fechar(contado: 110, troco: 100);

        $lancamentos = $this->service->contabilizar($this->sessao);

        $this->assertCount(1, $lancamentos);
        $this->assertNull($lancamentos->first()->maquininha_id);
    }

    public function test_estornar_devolve_os_saldos_e_permite_contabilizar_de_novo(): void
    {
        $this->vendasPadrao();
        $fechamento = $this->fechar(contado: 200, troco: 100);
        $this->service->contabilizar($this->sessao);

        $apagados = $this->service->estornar($this->sessao, 'Contagem errada');

        $this->assertSame(4, $apagados);
        $this->assertSame(100.0, $this->saldo($this->caixaPdv));
        $this->assertSame(0.0, $this->saldo($this->cofre));
        $this->assertSame(0.0, $this->saldo($this->contaPix));
        $this->assertFalse($this->service->contabilizada($this->sessao));
        $this->assertNull($fechamento->fresh()->recolhimento_transferencia_uuid);
        $this->assertTrue($this->service->podeContabilizar($this->sessao));

        $this->service->contabilizar($this->sessao);

        $this->assertSame(100.0, $this->saldo($this->caixaPdv));
        $this->assertSame(100.0, $this->saldo($this->cofre));
        $this->assertSame(50.0, $this->saldo($this->contaPix));
        $this->assertSame(0, MovimentacaoFinanceira::where('conta_financeira_id', $this->contaStone->id)->count());
    }

    public function test_estornar_exige_motivo(): void
    {
        $this->vender('cash', 100);
        $this->fechar(contado: 200, troco: 100);
        $this->service->contabilizar($this->sessao);

        $this->expectException(ValidationException::class);

        $this->service->estornar($this->sessao, 'ab');
    }

    public function test_estornar_com_recebivel_liquidado_e_bloqueado(): void
    {
        $this->vender('debitCard', 80);
        $this->vender('cash', 10);
        $this->fechar(contado: 110, troco: 100);
        $lancamentos = $this->service->contabilizar($this->sessao);
        $recebivel = $lancamentos->firstWhere('maquininha_id', $this->maquininha->id);
        $recebivel->registrarPagamento(80, today(), contaFinanceiraId: $this->contaStone->id, foraDaSessao: true);

        try {
            $this->service->estornar($this->sessao, 'Teste de bloqueio');
            $this->fail('Deveria bloquear.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('liquidados', collect($e->errors())->flatten()->first());
        }

        $this->assertTrue($this->service->contabilizada($this->sessao));
        $this->assertSame(80.0, $this->saldo($this->contaStone));
    }

    public function test_baixa_fora_da_sessao_no_caixa_pdv_nao_valida_saldo_da_gaveta(): void
    {
        $this->novaSessao(0)->update(['sessaocaixa_status' => 'ABERTA']);
        $despesa = Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'descricao' => 'Quebra',
            'valor' => 500,
            'vencimento' => today(),
            'status' => StatusLancamento::Pendente,
        ]);

        $pagamento = $despesa->registrarPagamento(500, today(), contaFinanceiraId: $this->caixaPdv->id, foraDaSessao: true);

        $this->assertNull($pagamento->sessao_caixa_id);
        $movimentacao = $pagamento->movimentacaoFinanceira;
        $this->assertSame(TipoMovimentacao::Saida, $movimentacao->tipo);
        $this->assertSame(NaturezaMovimentacao::PagamentoLancamento, $movimentacao->natureza);
        $this->assertSame(-400.0, $this->saldo($this->caixaPdv));
    }

    /** Importação antiga: título Pago sem conta e sem movimentação. */
    private function importacaoAntiga(): Lancamento
    {
        $titulo = Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'sessao_caixa_id' => $this->sessao->id,
            'plano_receita_id' => $this->planoReceita->id,
            'descricao' => 'Vendas antigas',
            'valor' => 100,
            'vencimento' => today(),
            'status' => StatusLancamento::Pendente,
        ]);
        $titulo->registrarPagamento(100, today());

        return $titulo;
    }

    public function test_backfill_em_dry_run_nao_grava_nada(): void
    {
        $this->vender('cash', 100);
        $this->fechar(contado: 200, troco: null);
        $antigo = $this->importacaoAntiga();

        $this->artisan('financeiro:contabilizar-fechamentos', ['--dry-run' => true, '--desde' => today()->subDay()->toDateString()])->assertSuccessful();

        $this->assertTrue($antigo->fresh() !== null);
        $this->assertSame(0, MovimentacaoFinanceira::count());
        $this->assertSame(100.0, $this->saldo($this->caixaPdv));
    }

    public function test_backfill_troca_a_importacao_antiga_pela_contabilizacao_nas_contas(): void
    {
        $this->vender('cash', 100);
        $this->fechar(contado: 200, troco: null);
        $antigo = $this->importacaoAntiga();

        $this->artisan('financeiro:contabilizar-fechamentos', ['--desde' => today()->subDay()->toDateString()])->assertSuccessful();

        $this->assertNull(Lancamento::find($antigo->id));
        $this->assertSame(200.0, $this->saldo($this->caixaPdv));
        $this->assertTrue($this->service->contabilizada($this->sessao));

        // Segunda execução não duplica.
        $this->artisan('financeiro:contabilizar-fechamentos', ['--desde' => today()->subDay()->toDateString()])->assertSuccessful();
        $this->assertSame(200.0, $this->saldo($this->caixaPdv));
    }

    public function test_backfill_respeita_o_corte(): void
    {
        $this->vender('cash', 100);
        $this->fechar(contado: 200, troco: null);

        $this->artisan('financeiro:contabilizar-fechamentos', ['--desde' => today()->addDay()->toDateString()])->assertSuccessful();

        $this->assertFalse($this->service->contabilizada($this->sessao));
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_sangria_antiga_sem_movimentacao_financeira_nao_trava_a_confirmacao(): void
    {
        $this->vender('cash', 100);
        // Sangria feita antes da contabilização no razão: só existe no controle da sessão.
        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $this->sessao->id,
            'mov_descricao' => 'Sangria antiga',
            'mov_tipo' => 'SAIDA',
            'mov_forma_pagamento' => 'dinheiro',
            'mov_motivo' => 'sangria',
            'mov_valor' => 6,
        ]);
        $this->fechar(contado: 194, troco: 100);

        $lancamentos = $this->service->contabilizar($this->sessao);

        $this->assertCount(1, $lancamentos);
        $this->assertSame(['A sessão tem R$ 6,00 de saídas em dinheiro feitas antes da contabilização no razão financeiro: esse valor não foi lançado nas contas.'], $this->service->avisos);
        // Recolhido = contado − troco; o razão fica R$ 6,00 acima do troco, pois a saída antiga nunca o tocou.
        $this->assertSame(94.0, $this->saldo($this->cofre));
        $this->assertSame(106.0, $this->saldo($this->caixaPdv));
    }

    public function test_troco_em_dinheiro_de_pix_na_maquininha_sai_da_gaveta_para_a_conta_da_maquininha(): void
    {
        $this->vender('cash', 100);
        // Pix na maquininha de 98,29: R$ 6,00 de troco saíram da gaveta em dinheiro.
        $this->vender('InstantPayment', 92.29, pagamento: ['pg_venda_valor_troco' => 6, 'pg_venda_forma_troco' => FormaTrocoEnum::Dinheiro]);
        $this->fechar(contado: 194, troco: 100);

        $this->service->contabilizar($this->sessao);

        $this->assertSame(100.0, $this->saldo($this->caixaPdv));
        $this->assertSame(94.0, $this->saldo($this->cofre));
        $this->assertSame(6.0, $this->saldo($this->contaStone));
    }
}
