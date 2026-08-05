<?php

namespace Tests\Feature;

use App\Enums\StatusFechamentoCaixa;
use App\Models\Caixa;
use App\Models\FechamentoCaixa;
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
