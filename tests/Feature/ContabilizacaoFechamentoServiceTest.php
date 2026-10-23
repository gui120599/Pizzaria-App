<?php

namespace Tests\Feature;

use App\Enums\OperadoraMaquininha;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Models\Caixa;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContabilizacaoFechamentoServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContabilizacaoFechamentoService $service;

    private SessaoCaixa $sessao;

    private PlanoReceita $planoReceita;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->service = app(ContabilizacaoFechamentoService::class);
        $this->planoReceita = PlanoReceita::create(['nome' => 'Balcão']);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Operador'])->id,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function vender(string $descNfe, float $valor, array $extra = [], ?int $planoReceitaId = -1, ?int $contaId = null, bool $pixCnpj = false): OpcoesPagamento
    {
        $opcao = OpcoesPagamento::create([
            'opcaopag_nome' => "Forma {$descNfe}",
            'opcaopag_desc_nfe' => $descNfe,
            'opcaopag_pix_cnpj' => $pixCnpj,
            'plano_receita_id' => $planoReceitaId === -1 ? $this->planoReceita->id : $planoReceitaId,
            'conta_financeira_id' => $contaId,
        ]);

        $venda = Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => $valor,
        ]);

        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => $valor,
            ...$extra,
        ]);

        return $opcao;
    }

    private function maquininha(?ContaFinanceira $conta): Maquininha
    {
        return Maquininha::create([
            'nome' => 'Stone 1',
            'operadora' => OperadoraMaquininha::Stone,
            'maquininha_padrao' => true,
            'conta_financeira_id' => $conta?->id,
        ]);
    }

    private function mensagens(): string
    {
        return implode(' | ', $this->service->pendenciasDeConfiguracao($this->sessao));
    }

    public function test_dinheiro_sem_conta_caixa_pdv_e_pendencia(): void
    {
        $this->vender('cash', 100);

        $this->assertStringContainsString('Caixa PDV', $this->mensagens());

        ContaFinanceira::factory()->caixaPdv()->create();

        $this->assertSame([], $this->service->pendenciasDeConfiguracao($this->sessao));
    }

    public function test_maquininha_sem_conta_e_pendencia_e_com_conta_libera(): void
    {
        $maquininha = $this->maquininha(null);
        $this->vender('debitCard', 80);

        $this->assertStringContainsString('A maquininha Stone 1 está sem conta financeira', $this->mensagens());

        $maquininha->update(['conta_financeira_id' => ContaFinanceira::factory()->create()->id]);

        $this->assertSame([], $this->service->pendenciasDeConfiguracao($this->sessao));
    }

    public function test_cartao_sem_maquininha_identificada_e_pendencia(): void
    {
        $this->vender('creditCard', 80);

        $this->assertStringContainsString('sem maquininha identificada', $this->mensagens());
    }

    public function test_pix_cnpj_sem_conta_e_pendencia_e_com_conta_libera(): void
    {
        $opcao = $this->vender('InstantPayment', 50, pixCnpj: true);

        $this->assertStringContainsString('Forma InstantPayment está sem conta financeira', $this->mensagens());

        $opcao->update(['conta_financeira_id' => ContaFinanceira::factory()->create()->id]);

        $this->assertSame([], $this->service->pendenciasDeConfiguracao($this->sessao));
    }

    public function test_forma_sem_plano_de_receita_e_pendencia(): void
    {
        ContaFinanceira::factory()->caixaPdv()->create();
        $this->vender('cash', 100, planoReceitaId: null);

        $this->assertStringContainsString('sem plano de receita', $this->mensagens());
    }

    public function test_taxa_de_cartao_exige_plano_de_despesa_na_empresa(): void
    {
        $this->maquininha(ContaFinanceira::factory()->create());
        $this->vender('creditCard', 100, ['pg_venda_taxa_maquininha_percentual' => 3.5, 'pg_venda_taxa_maquininha_valor' => 3.5]);

        $this->assertStringContainsString('taxa de cartão está sem plano de despesa', $this->mensagens());

        $plano = PlanoDespesa::create(['nome' => 'Taxas de cartão', 'comportamento' => 'variavel', 'periodicidade' => 'mensal']);
        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_plano_despesa_taxa_cartao_id' => $plano->id,
        ]);

        $this->assertSame([], $this->service->pendenciasDeConfiguracao($this->sessao));
    }

    public function test_forma_que_a_sessao_nao_usou_nao_bloqueia(): void
    {
        OpcoesPagamento::create(['opcaopag_nome' => 'Vale', 'opcaopag_desc_nfe' => 'others']);
        $this->maquininha(null);

        $this->assertSame([], $this->service->pendenciasDeConfiguracao($this->sessao));
    }

    public function test_select_de_conta_sem_caixa_pdv_nao_lista_o_caixa_pdv(): void
    {
        $caixaPdv = ContaFinanceira::factory()->caixaPdv()->create();
        $banco = ContaFinanceira::factory()->banco()->create();

        $this->assertArrayHasKey($caixaPdv->id, ContaFinanceiraSelect::opcoes());
        $this->assertArrayNotHasKey($caixaPdv->id, ContaFinanceiraSelect::opcoes(semCaixaPdv: true));
        $this->assertArrayHasKey($banco->id, ContaFinanceiraSelect::opcoes(semCaixaPdv: true));
    }
}
