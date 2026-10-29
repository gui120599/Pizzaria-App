<?php

namespace Tests\Feature;

use App\Enums\OperadoraMaquininha;
use App\Models\Caixa;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\MovimentacaoCaixaService;
use App\Services\RelatorioFechamentoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D1: o crédito lançado manualmente (Cielo) e o cobrado na Stone (Sunmi) caem
 * na MESMA forma e categoria; a diferença fica na maquininha/operadora.
 */
class StoneFechamentoManualEIntegradoTest extends TestCase
{
    use RefreshDatabase;

    public function test_credito_manual_e_credito_na_stone_somam_na_forma_e_separam_por_operadora(): void
    {
        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
        $credito = OpcoesPagamento::create([
            'opcaopag_nome' => 'Crédito', 'opcaopag_desc_nfe' => 'creditCard', 'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0, 'opcaopag_stone_integrada' => true,
        ]);
        $cielo = Maquininha::create(['nome' => 'Cielo Flash', 'operadora' => OperadoraMaquininha::Cielo]);
        $sunmi = Maquininha::create(['nome' => 'Sunmi', 'operadora' => OperadoraMaquininha::Stone, 'maquininha_connect_ativo' => true, 'numero_serie' => 'S1']);

        foreach ([[$cielo, 100.0, 'notIntegrated'], [$sunmi, 50.0, 'integrated']] as [$maquininha, $valor, $integracao]) {
            $venda = Venda::create([
                'venda_sessao_caixa_id' => $sessao->id, 'venda_status' => 'FINALIZADA', 'venda_valor_itens' => $valor,
                'venda_valor_total' => $valor, 'venda_valor_pago' => $valor, 'venda_datahora_iniciada' => now(), 'venda_datahora_finalizada' => now(),
            ]);
            PagamentosVenda::create([
                'pg_venda_venda_id' => $venda->id, 'pg_venda_opcaopagamento_id' => $credito->id, 'pg_venda_maquininha_id' => $maquininha->id,
                'pg_venda_tipo_integracao' => $integracao, 'pg_venda_valor_pagamento' => $valor,
            ]);
        }

        $relatorio = new RelatorioFechamentoCaixaService([]);

        $forma = $relatorio->porFormaPagamento()->sole();
        $this->assertSame('Crédito', $forma['forma']);
        $this->assertSame(2, $forma['transacoes']);
        $this->assertSame(150.0, $forma['bruto']);

        $operadoras = $relatorio->porOperadora()->keyBy('operadora');
        $this->assertSame(100.0, $operadoras['Cielo']['bruto']);
        $this->assertSame(50.0, $operadoras['Stone']['bruto']);
        $this->assertSame(150.0, $operadoras['Cielo']['credito'] + $operadoras['Stone']['credito']);

        $this->assertSame(150.0, app(MovimentacaoCaixaService::class)->recalcularSaldoFinal($sessao));
    }
}
