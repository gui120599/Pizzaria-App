<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\FormaPagamento;
use App\Enums\Periodicidade;
use App\Enums\Ponto\NaturezaAdiantamento;
use App\Enums\Ponto\NaturezaItem;
use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\SituacaoDia;
use App\Enums\Ponto\StatusAdiantamento;
use App\Enums\Ponto\StatusPeriodo;
use App\Enums\Ponto\StatusRepasse;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Exceptions\PontoException;
use App\Models\Adiantamento;
use App\Models\Colaborador;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\PontoDia;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use App\Models\User;
use App\Notifications\Ponto\RepasseDisponivelNotification;
use App\Services\Ponto\RepasseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RepasseServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PlanoDespesa $plano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-12 12:00:00');
        $this->admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($this->admin);
        $this->plano = PlanoDespesa::create(['nome' => 'Diárias e extras', 'comportamento' => Comportamento::Variavel, 'periodicidade' => Periodicidade::Mensal]);
        config(['ponto.plano_contas' => array_fill_keys(['clt', 'estagio', 'diarista', 'freelancer', 'mei_pj', 'entregador'], $this->plano->id)]);
    }

    public function test_diarista_recebe_as_diarias_e_a_hora_extra_sobre_a_carga_diaria(): void
    {
        $colaborador = Colaborador::factory()->comRemuneracao(['valor_base' => 120, 'carga_diaria_minutos' => 420])->create();
        $this->dias($colaborador, [480, 360, 360], ['minutos_extra_50' => 60]);
        $this->falta($colaborador, '2026-10-10');

        $repasse = $this->calcular($colaborador);

        // Hora = 120 / 7 h de carga; 1 h a 50%.
        $this->assertItens(['diarias' => 360.0, 'hora_extra_50' => 25.71], $repasse);
        $this->assertEquals(385.71, $repasse->valor_liquido);
    }

    public function test_por_hora_paga_horas_normais_extra_e_adicional_noturno(): void
    {
        $colaborador = Colaborador::factory()->comRemuneracao(['modalidade' => 'por_hora', 'valor_base' => 15])->create();
        $this->dias($colaborador, [400, 300], ['minutos_extra_50' => 40, 'minutos_noturnos' => 60, 'minutos_acrescimo_noturno' => 9]);

        $repasse = $this->calcular($colaborador);

        // (700 − 40 de extra) / 60 × 15; 40 min × 22,50/h; 69 min equivalentes × 15 × 20%.
        $this->assertItens(['horas_normais' => 165.0, 'hora_extra_50' => 15.0, 'adicional_noturno' => 3.45], $repasse);
    }

    public function test_mensal_desconta_falta_e_atraso_e_paga_extra_sobre_o_divisor_220(): void
    {
        $colaborador = Colaborador::factory()->clt()->comRemuneracao(['modalidade' => 'mensal', 'valor_base' => 2200, 'carga_semanal_minutos' => 2640])->create();
        $periodo = PontoPeriodo::factory()->entre('2026-09-01', '2026-09-30', PeriodicidadeRepasse::Mensal)->status(StatusPeriodo::EmConferencia)->create();
        $this->dias($colaborador, [480], ['minutos_extra_50' => 120, 'minutos_atraso' => 30], '2026-09-02');
        $this->falta($colaborador, '2026-09-03');

        $repasse = $this->calcular($colaborador, $periodo);

        $this->assertItens(['salario_base' => 2200.0, 'hora_extra_50' => 30.0, 'falta' => 73.33, 'atraso' => 5.0], $repasse);
        $this->assertEquals(2151.67, $repasse->valor_liquido);
    }

    public function test_entregador_recebe_por_entrega(): void
    {
        $colaborador = Colaborador::factory()->entregador()->comRemuneracao(['modalidade' => 'somente_entregas', 'valor_base' => 0, 'valor_por_entrega' => 6])->create();
        $this->dias($colaborador, [300, 300], ['entregas_qtd' => 8]);

        $this->assertItens(['entregas' => 48.0], $this->calcular($colaborador));
    }

    public function test_desconta_adiantamentos_abertos_ate_o_fim_do_periodo(): void
    {
        $colaborador = $this->diarista();
        $vale = Adiantamento::factory()->for($colaborador)->create(['data' => '2026-10-06', 'valor' => 50]);
        Adiantamento::factory()->for($colaborador)->consumo(20)->create(['data' => '2026-10-08']);
        Adiantamento::factory()->for($colaborador)->create(['data' => '2026-10-12', 'valor' => 70]);

        $repasse = $this->calcular($colaborador);

        $this->assertItens(['diarias' => 240.0, 'adiantamento' => 50.0, 'consumo' => 20.0], $repasse);
        $this->assertSame($vale->id, $repasse->itens->firstWhere('natureza', NaturezaItem::Adiantamento)->adiantamento_id);
        $this->assertEquals(170, $repasse->valor_liquido);
    }

    public function test_aprovar_cria_um_titulo_com_o_liquido_e_desconta_o_adiantamento(): void
    {
        Notification::fake();
        $colaborador = $this->diarista(['comUsuario' => true]);
        $vale = Adiantamento::factory()->for($colaborador)->create(['data' => '2026-10-06', 'valor' => 50]);
        $repasse = $this->calcular($colaborador);

        $lancamento = $this->servico()->aprovar($repasse, $this->admin);

        $this->assertSame(1, Lancamento::count());
        $this->assertSame(TipoLancamento::Pagar, $lancamento->tipo);
        $this->assertEquals(190, $lancamento->valor);
        $this->assertSame($this->plano->id, $lancamento->plano_despesa_id);
        $this->assertSame($colaborador->prestador->id, $lancamento->favorecido_id);
        $this->assertSame($repasse->id, $lancamento->repasse_id);
        $this->assertSame('2026-10-16', $lancamento->vencimento->toDateString());
        $this->assertSame(StatusRepasse::Aprovado, $repasse->fresh()->status);
        $this->assertSame(StatusAdiantamento::Descontado, $vale->fresh()->status);
        $this->assertSame($repasse->id, $vale->fresh()->repasse_id);
        $this->assertSame(StatusPeriodo::Fechado, $repasse->periodo->fresh()->status);
        Notification::assertSentTo($colaborador->user, RepasseDisponivelNotification::class);
    }

    public function test_baixa_do_titulo_paga_o_repasse_e_o_periodo_e_o_estorno_reverte(): void
    {
        $repasse = $this->calcular($this->diarista());
        $lancamento = $this->servico()->aprovar($repasse, $this->admin);
        $banco = ContaFinanceira::factory()->banco()->comSaldo(1000)->create();

        $lancamento->registrarPagamento(240, forma: FormaPagamento::Pix, contaFinanceiraId: $banco->id);

        $this->assertSame(StatusRepasse::Pago, $repasse->fresh()->status);
        $this->assertNotNull($repasse->fresh()->pago_em);
        $this->assertSame(StatusPeriodo::Pago, $repasse->periodo->fresh()->status);
        $this->assertEquals(760, $banco->fresh()->saldo_atual);

        $lancamento->fresh()->estornarPagamento('Pago em duplicidade');

        $this->assertSame(StatusRepasse::Aprovado, $repasse->fresh()->status);
        $this->assertNull($repasse->fresh()->pago_em);
        $this->assertSame(StatusPeriodo::Fechado, $repasse->periodo->fresh()->status);
    }

    public function test_baixa_parcial_nao_paga_o_repasse(): void
    {
        $lancamento = $this->servico()->aprovar($repasse = $this->calcular($this->diarista()), $this->admin);

        $lancamento->registrarPagamento(100, forma: FormaPagamento::Pix);

        $this->assertSame(StatusRepasse::Aprovado, $repasse->fresh()->status);
    }

    public function test_cancelar_aprovacao_com_titulo_pago_e_bloqueado(): void
    {
        $repasse = $this->calcular($this->diarista());
        $this->servico()->aprovar($repasse, $this->admin)->registrarPagamento(240, forma: FormaPagamento::Pix);

        $this->expectException(PontoException::class);

        $this->servico()->cancelarAprovacao($repasse->fresh(), $this->admin, 'Valor da diária estava errado');
    }

    public function test_cancelar_aprovacao_cancela_o_titulo_e_devolve_o_adiantamento(): void
    {
        $colaborador = $this->diarista();
        $vale = Adiantamento::factory()->for($colaborador)->create(['data' => '2026-10-06', 'valor' => 50]);
        $repasse = $this->calcular($colaborador);
        $lancamento = $this->servico()->aprovar($repasse, $this->admin);

        $this->servico()->cancelarAprovacao($repasse->fresh(), $this->admin, 'Valor da diária estava errado');

        $this->assertSame(StatusRepasse::Rascunho, $repasse->fresh()->status);
        $this->assertSame(StatusLancamento::Cancelado, $lancamento->fresh()->status);
        $this->assertSame(StatusAdiantamento::Aberto, $vale->fresh()->status);
        $this->assertNull($vale->fresh()->repasse_id);
        $this->assertSame(StatusPeriodo::EmConferencia, $repasse->periodo->fresh()->status);

        $novo = $this->servico()->aprovar($repasse->fresh(), $this->admin);

        $this->assertSame(2, $repasse->lancamentos()->count());
        $this->assertTrue($repasse->fresh()->lancamento->is($novo));
    }

    public function test_titulo_cancelado_no_financeiro_devolve_o_repasse_para_rascunho(): void
    {
        $repasse = $this->calcular($this->diarista());
        $lancamento = $this->servico()->aprovar($repasse, $this->admin);

        $lancamento->update(['status' => StatusLancamento::Cancelado]);

        $this->assertSame(StatusRepasse::Rascunho, $repasse->fresh()->status);
    }

    public function test_valor_do_titulo_de_repasse_nao_muda_no_financeiro(): void
    {
        $lancamento = $this->servico()->aprovar($this->calcular($this->diarista()), $this->admin);

        $this->expectException(FinanceiroException::class);

        $lancamento->update(['valor' => 999]);
    }

    public function test_clt_so_e_aprovado_conferido_com_o_holerite(): void
    {
        $colaborador = Colaborador::factory()->clt()->comRemuneracao(['modalidade' => 'mensal', 'valor_base' => 2200, 'carga_semanal_minutos' => 2640])->create();
        $periodo = PontoPeriodo::factory()->entre('2026-09-01', '2026-09-30', PeriodicidadeRepasse::Mensal)->status(StatusPeriodo::EmConferencia)->create();
        $repasse = $this->calcular($colaborador, $periodo);

        try {
            $this->servico()->aprovar($repasse, $this->admin);
            $this->fail('Repasse CLT aprovado sem conferência do holerite.');
        } catch (PontoException) {
        }

        $repasse->update(['conferido_holerite' => true]);

        $this->assertNotNull($this->servico()->aprovar($repasse->fresh(), $this->admin));
    }

    public function test_liquido_negativo_bloqueia_e_o_saldo_devedor_vai_para_o_proximo_periodo(): void
    {
        $colaborador = $this->diarista();
        Adiantamento::factory()->for($colaborador)->create(['data' => '2026-10-06', 'valor' => 300]);
        $repasse = $this->calcular($colaborador);

        try {
            $this->servico()->aprovar($repasse, $this->admin);
            $this->fail('Repasse com líquido negativo aprovado.');
        } catch (PontoException) {
        }

        $saldo = $this->servico()->transferirSaldoDevedor($repasse, $this->admin);
        $lancamento = $this->servico()->aprovar($repasse->fresh(), $this->admin);

        $this->assertSame(NaturezaAdiantamento::SaldoDevedor, $saldo->natureza);
        $this->assertEquals(60, $saldo->valor);
        $this->assertSame('2026-10-12', $saldo->data->toDateString());
        $this->assertNull($lancamento);
        $this->assertSame(StatusRepasse::Pago, $repasse->fresh()->status);
    }

    public function test_gerente_nao_aprova_repasse(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        $this->expectException(PontoException::class);

        $this->servico()->aprovar($this->calcular($this->diarista()), $gerente);
    }

    public function test_colaborador_ve_so_o_proprio_repasse_e_so_depois_de_aprovado(): void
    {
        $ana = $this->diarista(['comUsuario' => true]);
        $bruno = $this->diarista(['comUsuario' => true]);
        $periodo = PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->status(StatusPeriodo::EmConferencia)->create();
        $daAna = $this->calcular($ana, $periodo);
        $doBruno = $this->servico()->aprovar($this->calcular($bruno, $periodo), $this->admin)->repasse;
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        $this->assertFalse($ana->user->can('view', $daAna));
        $this->assertFalse($ana->user->can('view', $doBruno));
        $this->assertTrue($bruno->user->can('view', $doBruno));
        $this->assertFalse($gerente->can('view', $doBruno));
    }

    /** @param  array{comUsuario?: bool}  $opcoes */
    private function diarista(array $opcoes = []): Colaborador
    {
        $factory = Colaborador::factory()->comRemuneracao(['valor_base' => 120, 'carga_diaria_minutos' => 420]);
        $colaborador = ($opcoes['comUsuario'] ?? false ? $factory->comUsuario() : $factory)->create();
        $this->dias($colaborador, [360, 360]);

        return $colaborador;
    }

    /**
     * Dias apurados a partir de 07/10 (ou da data informada), um por elemento.
     *
     * @param  list<int>  $minutos
     * @param  array<string, mixed>  $extras  aplicados só no primeiro dia
     */
    private function dias(Colaborador $colaborador, array $minutos, array $extras = [], string $inicio = '2026-10-07'): void
    {
        foreach ($minutos as $i => $trabalhado) {
            PontoDia::factory()->for($colaborador)->create([
                'data_referencia' => now()->parse($inicio)->addDays($i)->toDateString(),
                'minutos_trabalhados' => $trabalhado,
                ...($i === 0 ? $extras : []),
            ]);
        }
    }

    private function falta(Colaborador $colaborador, string $dia): void
    {
        PontoDia::factory()->for($colaborador)->create([
            'data_referencia' => $dia,
            'situacao' => SituacaoDia::Falta,
            'minutos_trabalhados' => 0,
            'minutos_falta' => 360,
        ]);
    }

    private function calcular(Colaborador $colaborador, ?PontoPeriodo $periodo = null): Repasse
    {
        $periodo ??= PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->create(['status' => StatusPeriodo::EmConferencia, 'data_pagamento' => '2026-10-16']);
        $repasse = Repasse::factory()->for($periodo, 'periodo')->for($colaborador)->create();

        $this->servico()->calcularItens($repasse);

        return $repasse->fresh('itens');
    }

    /** @param  array<string, float>  $esperado  natureza => valor */
    private function assertItens(array $esperado, Repasse $repasse): void
    {
        $obtido = $repasse->itens->mapWithKeys(fn ($item): array => [$item->natureza->value => (float) $item->valor])->sortKeys()->all();
        ksort($esperado);

        $this->assertSame($esperado, $obtido);
    }

    private function servico(): RepasseService
    {
        return app(RepasseService::class);
    }
}
