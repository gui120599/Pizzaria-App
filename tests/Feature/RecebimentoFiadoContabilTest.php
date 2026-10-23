<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\OperadoraMaquininha;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Enums\TipoPagamentoMaquininhaEnum;
use App\Models\Caixa;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\Lancamento;
use App\Models\Maquininha;
use App\Models\MaquininhaTaxa;
use App\Models\MovimentacaoFinanceira;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\FechamentoCaixaService;
use App\Services\Financeiro\RecebimentoFiadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecebimentoFiadoContabilTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHour(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Operador'])->id,
        ]);
        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_plano_despesa_taxa_cartao_id' => PlanoDespesa::create(['nome' => 'Taxas de cartão', 'comportamento' => 'variavel', 'periodicidade' => 'mensal'])->id,
        ]);
    }

    private function fiado(float $valor = 100): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'plano_receita_id' => PlanoReceita::firstOrCreate(['nome' => 'Balcão'])->id,
            'descricao' => 'Fiado',
            'valor' => $valor,
            'vencimento' => today(),
            'status' => StatusLancamento::Pendente,
        ]);
    }

    public function test_fiado_em_dinheiro_entra_uma_vez_no_caixa_pdv_e_a_sessao_conta_como_antes(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();

        app(RecebimentoFiadoService::class)->receber($this->fiado(), 40, FormaPagamento::Dinheiro, null, $this->sessao->id);

        $this->assertSame(40.0, (float) $pdv->fresh()->saldo_atual);
        $this->assertSame(1, MovimentacaoFinanceira::count());
        $this->assertSame($this->sessao->id, MovimentacaoFinanceira::first()->sessao_caixa_id);
        $this->assertEqualsWithDelta(40.0, app(FechamentoCaixaService::class)->calcularEsperado($this->sessao)['dinheiro'], 0.001);
    }

    public function test_fiado_em_cartao_entra_na_conta_da_maquininha_com_a_taxa_como_despesa(): void
    {
        $conta = ContaFinanceira::factory()->banco()->create();
        $maquininha = Maquininha::create(['nome' => 'Stone 1', 'operadora' => OperadoraMaquininha::Stone, 'maquininha_padrao' => true, 'conta_financeira_id' => $conta->id]);
        MaquininhaTaxa::create(['mt_maquininha_id' => $maquininha->id, 'mt_tipo' => TipoPagamentoMaquininhaEnum::Debito, 'mt_percentual' => 2]);

        $pagamento = app(RecebimentoFiadoService::class)->receber($this->fiado(), 100, FormaPagamento::CartaoDebito, null, $this->sessao->id, maquininhaId: $maquininha->id);

        $this->assertSame($this->sessao->id, $pagamento->sessao_caixa_id);
        $this->assertEqualsWithDelta(98.0, (float) $conta->fresh()->saldo_atual, 0.001);
        $this->assertSame(1, Lancamento::where('tipo', TipoLancamento::Pagar)->count());
    }

    public function test_fiado_em_forma_sem_conta_cadastrada_continua_sem_movimentar(): void
    {
        $pagamento = app(RecebimentoFiadoService::class)->receber($this->fiado(), 30, FormaPagamento::Boleto, null, null);

        $this->assertNull($pagamento->conta_financeira_id);
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }
}
