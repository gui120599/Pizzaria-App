<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Models\Caixa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\FinalizacaoVendaService;
use App\Services\MovimentacaoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MovimentacaoCaixaServiceTest extends TestCase
{
    use RefreshDatabase;

    private MovimentacaoCaixaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->service = app(MovimentacaoCaixaService::class);
    }

    private function sessaoAberta(float $saldoInicial = 100.0): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => $saldoInicial,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
    }

    public function test_registrar_saida_cria_movimento_e_recalcula_saldo(): void
    {
        $sessao = $this->sessaoAberta(100.0);

        $movimento = $this->service->registrarSaida(
            sessao: $sessao,
            valor: 30.0,
            formaPagamento: FormaPagamento::Dinheiro,
            motivo: MotivoSaidaCaixa::Sangria,
            descricao: 'Depósito no banco',
        );

        $this->assertSame('SAIDA', $movimento->mov_tipo);
        $this->assertSame(MotivoSaidaCaixa::Sangria, $movimento->mov_motivo);
        $this->assertSame(auth()->id(), $movimento->mov_user_id);
        $this->assertEqualsWithDelta(30.0, (float) $movimento->mov_valor, 0.01);
        $this->assertEqualsWithDelta(70.0, (float) $sessao->fresh()->sessaocaixa_saldo_final, 0.01);
    }

    public function test_registrar_suprimento_cria_movimento_entrada_e_recalcula_saldo(): void
    {
        $sessao = $this->sessaoAberta(100.0);

        $movimento = $this->service->registrarSuprimento(
            sessao: $sessao,
            valor: 50.0,
            formaPagamento: FormaPagamento::Dinheiro,
            descricao: 'Reforço de troco',
        );

        $this->assertSame('ENTRADA', $movimento->mov_tipo);
        $this->assertSame(MotivoSaidaCaixa::Suprimento, $movimento->mov_motivo);
        $this->assertEqualsWithDelta(150.0, (float) $sessao->fresh()->sessaocaixa_saldo_final, 0.01);
    }

    public function test_registrar_saida_em_sessao_fechada_lanca_validation(): void
    {
        $sessao = $this->sessaoAberta();
        $sessao->update(['sessaocaixa_status' => 'FECHADA']);

        $this->expectException(ValidationException::class);

        $this->service->registrarSaida($sessao, 10.0, FormaPagamento::Dinheiro, MotivoSaidaCaixa::Sangria, 'x');
    }

    public function test_registrar_saida_valor_zero_ou_negativo_lanca_validation(): void
    {
        $sessao = $this->sessaoAberta();

        $this->expectException(ValidationException::class);

        $this->service->registrarSaida($sessao, 0.0, FormaPagamento::Dinheiro, MotivoSaidaCaixa::Sangria, 'x');
    }

    public function test_recalcular_saldo_final_e_idempotente(): void
    {
        $sessao = $this->sessaoAberta(100.0);
        $this->service->registrarSaida($sessao, 20.0, FormaPagamento::Dinheiro, MotivoSaidaCaixa::Sangria, 'x');

        $primeiro = $this->service->recalcularSaldoFinal($sessao->fresh());
        $segundo = $this->service->recalcularSaldoFinal($sessao->fresh());

        $this->assertEqualsWithDelta(80.0, $primeiro, 0.01);
        $this->assertEqualsWithDelta($primeiro, $segundo, 0.01);
    }

    /** Movimentos automáticos (abertura, venda) não têm mov_motivo — não entram na soma de "manuais". */
    public function test_recalcular_ignora_movimentos_automaticos_sem_motivo(): void
    {
        $sessao = $this->sessaoAberta(100.0);

        // Movimento de abertura (mov_motivo nulo) — não deve ser somado de novo
        // além do que já está em sessaocaixa_saldo_inicial.
        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $sessao->id,
            'mov_descricao' => 'Saldo inicial de abertura',
            'mov_tipo' => 'ENTRADA',
            'mov_forma_pagamento' => FormaPagamento::Dinheiro,
            'mov_valor' => 100.0,
        ]);

        $saldo = $this->service->recalcularSaldoFinal($sessao->fresh());

        $this->assertEqualsWithDelta(100.0, $saldo, 0.01);
    }

    /**
     * Regressão do bug "último a escrever vence": antes desta classe, cada ponto
     * que finalizava uma venda reescrevia sessaocaixa_saldo_final do zero
     * (saldo_inicial + vendas), apagando qualquer sangria já registrada.
     */
    public function test_finalizacao_venda_preserva_sangria_registrada_antes(): void
    {
        $sessao = $this->sessaoAberta(100.0);
        $this->service->registrarSaida($sessao, 40.0, FormaPagamento::Dinheiro, MotivoSaidaCaixa::Sangria, 'Sangria antes da venda');
        $this->assertEqualsWithDelta(60.0, (float) $sessao->fresh()->sessaocaixa_saldo_final, 0.01);

        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessao->id,
            'venda_valor_total' => 50.0,
            'venda_valor_pago' => 50.0,
            'venda_valor_troco' => 0,
        ]);

        app(FinalizacaoVendaService::class)->finalizar($venda);

        // 100 (inicial) + 50 (venda) - 40 (sangria) = 110 — a sangria continua valendo.
        $this->assertEqualsWithDelta(110.0, (float) $sessao->fresh()->sessaocaixa_saldo_final, 0.01);
    }
}
