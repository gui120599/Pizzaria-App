<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Enums\Periodicidade;
use App\Enums\Ponto\NaturezaAdiantamento;
use App\Enums\Ponto\StatusAdiantamento;
use App\Enums\Ponto\StatusPeriodo;
use App\Enums\StatusLancamento;
use App\Exceptions\FinanceiroException;
use App\Exceptions\PontoException;
use App\Models\Adiantamento;
use App\Models\Caixa;
use App\Models\Colaborador;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\PlanoDespesa;
use App\Models\PontoDia;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\FechamentoCaixaService;
use App\Services\Ponto\AdiantamentoService;
use App\Services\Ponto\RepasseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdiantamentoServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $gerente;

    private PlanoDespesa $plano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 20:00:00');
        $this->gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $this->actingAs($this->gerente);
        $this->plano = PlanoDespesa::create(['nome' => 'Diárias e extras', 'comportamento' => Comportamento::Variavel, 'periodicidade' => Periodicidade::Mensal]);
        config(['ponto.plano_contas' => array_fill_keys(['clt', 'estagio', 'diarista', 'freelancer', 'mei_pj', 'entregador'], $this->plano->id)]);
    }

    public function test_vale_saindo_do_cofre_gera_titulo_pago_e_uma_movimentacao_sem_tocar_na_sessao(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->comSaldo(500)->create();
        $sessao = $this->sessaoAberta(300);
        $colaborador = Colaborador::factory()->create();

        $vale = $this->registrar($colaborador, 100, $cofre);

        $lancamento = Lancamento::sole();
        $this->assertSame($vale->id, $lancamento->adiantamento_id);
        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertSame($this->plano->id, $lancamento->plano_despesa_id);
        $this->assertSame($colaborador->prestador->id, $lancamento->favorecido_id);
        $this->assertSame(FormaPagamento::Dinheiro, $lancamento->forma_pagamento);
        $movimentacao = MovimentacaoFinanceira::sole();
        $this->assertSame(NaturezaMovimentacao::Adiantamento, $movimentacao->natureza);
        $this->assertSame($vale->id, $movimentacao->adiantamento_id);
        $this->assertEquals(400, $cofre->fresh()->saldo_atual);
        $this->assertSame(0, MovimentacoesSessaoCaixa::query()->where('mov_sessaocaixa_id', $sessao->id)->manuais()->count());
        $this->assertNull($vale->sessao_caixa_id);
    }

    public function test_vale_saindo_do_caixa_pdv_vira_retirada_ligada_na_sessao_aberta(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $sessao = $this->sessaoAberta(300);

        $vale = $this->registrar(Colaborador::factory()->create(), 100, $pdv);

        $retirada = MovimentacoesSessaoCaixa::query()->whereNotNull('movimentacao_financeira_id')->sole();
        $this->assertSame(MotivoSaidaCaixa::PagamentoDespesa, $retirada->mov_motivo);
        $this->assertSame($sessao->id, $vale->sessao_caixa_id);
        $this->assertEqualsWithDelta(200, app(FechamentoCaixaService::class)->calcularEsperado($sessao)['dinheiro'], 0.001);
    }

    public function test_vale_do_caixa_pdv_sem_sessao_aberta_desfaz_tudo(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();

        try {
            $this->registrar(Colaborador::factory()->create(), 100, $pdv);
            $this->fail('Vale do Caixa PDV sem sessão aberta foi registrado.');
        } catch (FinanceiroException) {
        }

        $this->assertSame(0, Adiantamento::count());
        $this->assertSame(0, Lancamento::count());
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_vale_exige_a_conta_de_onde_sai_o_dinheiro(): void
    {
        $this->expectException(PontoException::class);

        $this->registrar(Colaborador::factory()->create(), 100, null);
    }

    public function test_consumo_nao_movimenta_dinheiro(): void
    {
        $consumo = app(AdiantamentoService::class)->registrar(Colaborador::factory()->create(), NaturezaAdiantamento::Consumo, 35, today(), 'Pizza', $this->gerente);

        $this->assertSame(StatusAdiantamento::Aberto, $consumo->status);
        $this->assertSame(0, Lancamento::count());
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_cancelar_vale_devolve_o_dinheiro_e_cancela_o_titulo(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $cofre = ContaFinanceira::factory()->cofre()->comSaldo(500)->create();
        $vale = $this->registrar(Colaborador::factory()->create(), 100, $cofre);

        app(AdiantamentoService::class)->cancelar($vale, $admin, 'Vale lançado em duplicidade');

        $this->assertSame(StatusAdiantamento::Cancelado, $vale->fresh()->status);
        $this->assertSame(StatusLancamento::Cancelado, Lancamento::sole()->status);
        $this->assertEquals(500, $cofre->fresh()->saldo_atual);
    }

    public function test_gerente_nao_cancela_vale(): void
    {
        $vale = $this->registrar(Colaborador::factory()->create(), 100, ContaFinanceira::factory()->cofre()->comSaldo(500)->create());

        $this->expectException(PontoException::class);

        app(AdiantamentoService::class)->cancelar($vale, $this->gerente, 'Vale lançado em duplicidade');
    }

    public function test_vale_descontado_nao_e_cancelado(): void
    {
        $vale = Adiantamento::factory()->create(['status' => StatusAdiantamento::Descontado]);

        $this->expectException(PontoException::class);

        app(AdiantamentoService::class)->cancelar($vale, User::factory()->admin()->create(['name_first' => 'Admin']), 'Vale lançado em duplicidade');
    }

    public function test_vale_mais_liquido_do_repasse_soma_o_bruto_sem_duplicar_despesa(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $colaborador = Colaborador::factory()->comRemuneracao(['valor_base' => 120])->create();
        $this->registrar($colaborador, 100, ContaFinanceira::factory()->cofre()->comSaldo(500)->create());
        foreach (['2026-10-07', '2026-10-08', '2026-10-09'] as $dia) {
            PontoDia::factory()->for($colaborador)->create(['data_referencia' => $dia, 'minutos_trabalhados' => 360]);
        }
        $periodo = PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->status(StatusPeriodo::EmConferencia)->create();
        $repasse = Repasse::factory()->for($periodo, 'periodo')->for($colaborador)->create();
        app(RepasseService::class)->calcularItens($repasse);

        app(RepasseService::class)->aprovar($repasse->fresh(), $admin);

        $this->assertEquals(360, $repasse->fresh()->total_proventos);
        $this->assertEquals(360, Lancamento::query()->where('favorecido_id', $colaborador->prestador->id)->sum('valor'));
    }

    private function registrar(Colaborador $colaborador, float $valor, ?ContaFinanceira $conta): Adiantamento
    {
        return app(AdiantamentoService::class)->registrar($colaborador, NaturezaAdiantamento::Vale, $valor, Carbon::today(), 'Vale da semana', $this->gerente, $conta);
    }

    private function sessaoAberta(float $dinheiro): SessaoCaixa
    {
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHour(),
            'sessaocaixa_saldo_inicial' => $dinheiro,
            'sessaocaixa_saldo_final' => $dinheiro,
            'sessaocaixa_user_id' => $this->gerente->id,
        ]);

        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $sessao->id,
            'mov_descricao' => 'Abertura',
            'mov_tipo' => 'ENTRADA',
            'mov_forma_pagamento' => FormaPagamento::Dinheiro,
            'mov_user_id' => $this->gerente->id,
            'mov_valor' => $dinheiro,
        ]);

        return $sessao;
    }
}
