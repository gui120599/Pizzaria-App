<?php

namespace Tests\Feature;

use App\Enums\StatusContrato;
use App\Enums\StatusLancamento;
use App\Models\Contrato;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Services\ContratoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContratoServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContratoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContratoService::class);
    }

    private function fornecedor(): Prestador
    {
        return Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Aluguel Comercial LTDA',
            'nome' => 'Aluguel Comercial',
            'cpf_cnpj' => '11222333000144',
        ]);
    }

    private function planoDespesa(): PlanoDespesa
    {
        return PlanoDespesa::create([
            'nome' => 'Aluguel',
            'comportamento' => 'fixo',
        ]);
    }

    private function contrato(array $attrs = []): Contrato
    {
        return Contrato::create(array_merge([
            'favorecido_id' => $this->fornecedor()->id,
            'plano_despesa_id' => $this->planoDespesa()->id,
            'descricao' => 'Aluguel do salão',
            'valor' => 1500.00,
            'dia_vencimento' => 10,
            'data_inicio' => now()->subMonths(2)->startOfMonth(),
            'status' => StatusContrato::Ativo,
        ], $attrs));
    }

    public function test_gera_lancamento_mensal_para_contrato_ativo_e_vigente(): void
    {
        $contrato = $this->contrato();
        $competencia = now()->startOfMonth();

        $lancamento = $this->service->gerarLancamentoMensal($contrato, $competencia);

        $this->assertNotNull($lancamento);
        $this->assertSame($contrato->id, $lancamento->contrato_id);
        $this->assertTrue($lancamento->competencia->isSameDay($competencia));
        $this->assertSame($contrato->favorecido_id, $lancamento->favorecido_id);
        $this->assertSame($contrato->plano_despesa_id, $lancamento->plano_despesa_id);
        $this->assertEqualsWithDelta(1500.0, (float) $lancamento->valor, 0.01);
        $this->assertSame(StatusLancamento::Pendente, $lancamento->status);
        $this->assertSame(10, $lancamento->vencimento->day);
    }

    public function test_nao_duplica_lancamento_na_mesma_competencia(): void
    {
        $contrato = $this->contrato();
        $competencia = now()->startOfMonth();

        $primeiro = $this->service->gerarLancamentoMensal($contrato, $competencia);
        $segundo = $this->service->gerarLancamentoMensal($contrato, $competencia);

        $this->assertNotNull($primeiro);
        $this->assertNull($segundo);
        $this->assertSame(1, Lancamento::where('contrato_id', $contrato->id)->count());
    }

    public function test_nao_gera_para_contrato_com_data_inicio_no_futuro(): void
    {
        $contrato = $this->contrato(['data_inicio' => now()->addMonth()->startOfMonth()]);

        $lancamento = $this->service->gerarLancamentoMensal($contrato, now()->startOfMonth());

        $this->assertNull($lancamento);
        $this->assertSame(0, Lancamento::where('contrato_id', $contrato->id)->count());
    }

    public function test_nao_gera_para_contrato_com_data_fim_ja_passada(): void
    {
        $contrato = $this->contrato(['data_fim' => now()->subMonth()->endOfMonth()]);

        $lancamento = $this->service->gerarLancamentoMensal($contrato, now()->startOfMonth());

        $this->assertNull($lancamento);
    }

    public function test_nao_gera_para_contrato_suspenso_ou_encerrado(): void
    {
        $suspenso = $this->contrato(['status' => StatusContrato::Suspenso]);
        $encerrado = $this->contrato(['status' => StatusContrato::Encerrado]);
        $competencia = now()->startOfMonth();

        $this->assertNull($this->service->gerarLancamentoMensal($suspenso, $competencia));
        $this->assertNull($this->service->gerarLancamentoMensal($encerrado, $competencia));
    }

    public function test_dia_vencimento_31_em_mes_curto_cai_no_ultimo_dia(): void
    {
        $contrato = $this->contrato([
            'dia_vencimento' => 31,
            'data_inicio' => Carbon::create(2026, 1, 1),
        ]);

        $lancamento = $this->service->gerarLancamentoMensal($contrato, Carbon::create(2026, 2, 1));

        $this->assertNotNull($lancamento);
        $this->assertTrue($lancamento->vencimento->isSameDay(Carbon::create(2026, 2, 28)));
    }

    public function test_encerrar_vencidos_marca_status_encerrado(): void
    {
        $vencido = $this->contrato(['data_fim' => now()->subDay()]);
        $vigente = $this->contrato(['data_fim' => now()->addMonth()]);

        $total = $this->service->encerrarVencidos();

        $this->assertSame(1, $total);
        $this->assertSame(StatusContrato::Encerrado, $vencido->fresh()->status);
        $this->assertSame(StatusContrato::Ativo, $vigente->fresh()->status);
    }

    public function test_gerar_lancamentos_do_mes_processa_todos_os_contratos_ativos_vigentes(): void
    {
        $ativo1 = $this->contrato();
        $ativo2 = $this->contrato();
        $foraDeVigencia = $this->contrato(['data_inicio' => now()->addMonth()->startOfMonth()]);

        $gerados = $this->service->gerarLancamentosDoMes(now());

        $this->assertCount(2, $gerados);
        $this->assertSame(1, Lancamento::where('contrato_id', $ativo1->id)->count());
        $this->assertSame(1, Lancamento::where('contrato_id', $ativo2->id)->count());
        $this->assertSame(0, Lancamento::where('contrato_id', $foraDeVigencia->id)->count());
    }
}
