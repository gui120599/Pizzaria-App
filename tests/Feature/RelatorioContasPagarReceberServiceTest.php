<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Cliente;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\Prestador;
use App\Services\RelatorioContasPagarReceberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RelatorioContasPagarReceberServiceTest extends TestCase
{
    use RefreshDatabase;

    private Prestador $fornecedor;

    private Cliente $cliente;

    private PlanoDespesa $planoDespesa;

    private PlanoReceita $planoReceita;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fornecedor = Prestador::create([
            'tipo' => PrestadorTipoEnum::PJ,
            'categoria' => PrestadorCategoriaEnum::FORNECEDOR,
            'nome_fantasia' => 'Fornecedor Teste',
        ]);

        $this->cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_tipo' => 'PF']);

        $this->planoDespesa = PlanoDespesa::create([
            'nome' => 'Aluguel',
            'comportamento' => Comportamento::Fixo,
        ]);

        $this->planoReceita = PlanoReceita::create(['nome' => 'Vendas']);

        // A Pagar — vencido há 5 dias (aging 1-15), com favorecido e plano de despesa.
        $this->criarLancamento(TipoLancamento::Pagar, 200, now()->subDays(5), [
            'favorecido_id' => $this->fornecedor->id,
            'plano_despesa_id' => $this->planoDespesa->id,
        ]);

        // A Pagar — a vencer em 10 dias (entra nas janelas cumulativas de 15/30/60).
        $this->criarLancamento(TipoLancamento::Pagar, 300, now()->addDays(10), [
            'favorecido_id' => $this->fornecedor->id,
        ]);

        // A Pagar — pago, com created_at/data_pagamento controlados pra testar o PMP.
        $pago = $this->criarLancamento(TipoLancamento::Pagar, 100, now()->subDays(20));
        DB::table('lancamentos')->where('id', $pago->id)->update(['created_at' => now()->subDays(20)]);
        $pago->registrarPagamento(100, Carbon::now()->subDays(10));

        // A Receber — vencido há 20 dias (aging 16-30), com cliente.
        $this->criarLancamento(TipoLancamento::Receber, 150, now()->subDays(20), [
            'cliente_id' => $this->cliente->id,
            'plano_receita_id' => $this->planoReceita->id,
        ]);

        // A Receber — a vencer em 40 dias.
        $this->criarLancamento(TipoLancamento::Receber, 400, now()->addDays(40), [
            'cliente_id' => $this->cliente->id,
        ]);

        // A Receber — cancelado (não deve entrar em nenhum total em aberto/vencido).
        $this->criarLancamento(TipoLancamento::Receber, 999, now()->subDays(3), [
            'status' => StatusLancamento::Cancelado,
        ]);
    }

    private function criarLancamento(TipoLancamento $tipo, float $valor, Carbon $vencimento, array $extra = []): Lancamento
    {
        return Lancamento::create(array_merge([
            'tipo' => $tipo,
            'descricao' => 'Lançamento de teste',
            'valor' => $valor,
            'vencimento' => $vencimento,
        ], $extra));
    }

    public function test_totais_aberto_e_vencido_a_pagar(): void
    {
        $totais = (new RelatorioContasPagarReceberService)->totaisAbertoEVencido(TipoLancamento::Pagar);

        $this->assertEqualsWithDelta(500.0, $totais['total_aberto'], 0.01);
        $this->assertEqualsWithDelta(200.0, $totais['total_vencido'], 0.01);
        $this->assertEqualsWithDelta(40.0, $totais['percentual_vencido'], 0.01);
    }

    public function test_totais_aberto_e_vencido_a_receber_com_indice_de_inadimplencia(): void
    {
        $totais = (new RelatorioContasPagarReceberService)->totaisAbertoEVencido(TipoLancamento::Receber);

        // O cancelado (999) não entra: só os dois pendentes (150 + 400 = 550).
        $this->assertEqualsWithDelta(550.0, $totais['total_aberto'], 0.01);
        $this->assertEqualsWithDelta(150.0, $totais['total_vencido'], 0.01);
        $this->assertEqualsWithDelta(150 / 550 * 100, $totais['indice_inadimplencia'], 0.01);
    }

    public function test_aging_vencidos_classifica_por_faixa_de_atraso(): void
    {
        $service = new RelatorioContasPagarReceberService;

        $this->assertEqualsWithDelta(200.0, $service->agingVencidos(TipoLancamento::Pagar)['1-15'], 0.01);
        $this->assertEqualsWithDelta(0.0, $service->agingVencidos(TipoLancamento::Pagar)['16-30'], 0.01);
        $this->assertEqualsWithDelta(150.0, $service->agingVencidos(TipoLancamento::Receber)['16-30'], 0.01);
    }

    public function test_aging_a_vencer_e_cumulativo(): void
    {
        $aging = (new RelatorioContasPagarReceberService)->agingAVencer(TipoLancamento::Pagar);

        // Vence em 10 dias: não entra na janela de 7, entra nas de 15/30/60.
        $this->assertEqualsWithDelta(0.0, $aging['7'], 0.01);
        $this->assertEqualsWithDelta(300.0, $aging['15'], 0.01);
        $this->assertEqualsWithDelta(300.0, $aging['30'], 0.01);
        $this->assertEqualsWithDelta(300.0, $aging['60'], 0.01);
    }

    public function test_top_favorecidos_soma_saldo_em_aberto_por_fornecedor(): void
    {
        $top = (new RelatorioContasPagarReceberService)->topFavorecidos(TipoLancamento::Pagar);

        $this->assertCount(1, $top);
        $this->assertSame('Fornecedor Teste', $top->first()['nome']);
        $this->assertEqualsWithDelta(500.0, $top->first()['saldo'], 0.01);
    }

    public function test_pmp_calcula_media_ponderada_de_dias_entre_lancamento_e_pagamento(): void
    {
        $pmp = (new RelatorioContasPagarReceberService)->pmp();

        // Único título pago: lançado há 20 dias, pago há 10 dias -> 10 dias de prazo.
        $this->assertEqualsWithDelta(10.0, $pmp, 0.01);
    }

    public function test_lancamentos_detalhados_agrupa_por_situacao_incluindo_cancelado(): void
    {
        $grupos = (new RelatorioContasPagarReceberService)->lancamentosDetalhados();

        $this->assertTrue($grupos->has('Vencido'));
        $this->assertTrue($grupos->has('A vencer'));
        $this->assertTrue($grupos->has('Pago'));
        $this->assertTrue($grupos->has('Cancelado'));
        $this->assertCount(1, $grupos['Cancelado']);
    }

    public function test_filtro_por_tipo_restringe_a_query(): void
    {
        $service = new RelatorioContasPagarReceberService(['tipo' => 'pagar']);

        $this->assertSame(3, $service->query()->count());
    }

    public function test_filtro_por_favorecido_restringe_a_query(): void
    {
        $service = new RelatorioContasPagarReceberService(['favorecido_id' => $this->fornecedor->id]);

        $this->assertSame(2, $service->query()->count());
    }

    public function test_saldo_projetado_por_faixa_e_receber_menos_pagar(): void
    {
        $saldo = (new RelatorioContasPagarReceberService)->saldoProjetadoPorFaixa();

        // Vencido: Receber 150 - Pagar 200 = -50.
        $this->assertEqualsWithDelta(-50.0, $saldo['vencido'], 0.01);
    }
}
