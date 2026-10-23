<?php

namespace Tests\Feature;

use App\Enums\OperadoraMaquininha;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\Lancamento;
use App\Models\Maquininha;
use App\Models\MovimentacaoFinanceira;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\User;
use App\Services\Financeiro\LiquidacaoRecebiveisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiquidacaoRecebiveisServiceTest extends TestCase
{
    use RefreshDatabase;

    private LiquidacaoRecebiveisService $service;

    private ContaFinanceira $contaStone;

    private Maquininha $maquininha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->service = app(LiquidacaoRecebiveisService::class);
        $this->contaStone = ContaFinanceira::factory()->banco()->create(['nome' => 'Conta Stone']);
        $this->maquininha = Maquininha::create(['nome' => 'Stone 1', 'operadora' => OperadoraMaquininha::Stone, 'conta_financeira_id' => $this->contaStone->id]);

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_plano_despesa_taxa_cartao_id' => PlanoDespesa::create(['nome' => 'Taxas de cartão', 'comportamento' => 'variavel', 'periodicidade' => 'mensal'])->id,
        ]);
    }

    private function recebivel(string $vencimento = 'today', float $valor = 80, float $taxa = 1.2): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'plano_receita_id' => PlanoReceita::firstOrCreate(['nome' => 'Balcão'])->id,
            'descricao' => 'Vendas Débito (Stone 1)',
            'valor' => $valor,
            'vencimento' => $vencimento === 'today' ? today() : today()->addDays((int) $vencimento),
            'status' => StatusLancamento::Pendente,
            'maquininha_id' => $this->maquininha->id,
            'taxa_adquirente' => $taxa,
        ]);
    }

    private function saldo(): float
    {
        return (float) $this->contaStone->fresh()->saldo_atual;
    }

    public function test_liquidar_lanca_o_bruto_e_a_taxa_na_conta_da_maquininha(): void
    {
        $titulo = $this->recebivel();

        $this->service->liquidar($titulo);

        $this->assertSame(StatusLancamento::Pago, $titulo->fresh()->status);
        $this->assertEqualsWithDelta(78.8, $this->saldo(), 0.001);
        $this->assertSame(2, MovimentacaoFinanceira::where('conta_financeira_id', $this->contaStone->id)->count());
        $taxa = Lancamento::where('recebivel_id', $titulo->id)->firstOrFail();
        $this->assertSame(StatusLancamento::Pago, $taxa->status);
        $this->assertSame(TipoLancamento::Pagar, $taxa->tipo);
        $this->assertEqualsWithDelta(1.2, (float) $taxa->valor, 0.001);
    }

    public function test_recebivel_sem_taxa_nao_cria_titulo_de_taxa(): void
    {
        $titulo = $this->recebivel(taxa: 0);

        $this->service->liquidar($titulo);

        $this->assertSame(80.0, $this->saldo());
        $this->assertSame(0, Lancamento::where('recebivel_id', $titulo->id)->count());
    }

    public function test_valor_liquido_informado_ajusta_a_taxa(): void
    {
        $titulo = $this->recebivel();

        $this->service->liquidar($titulo, valorLiquido: 77.0);

        $this->assertEqualsWithDelta(77.0, $this->saldo(), 0.001);
        $this->assertEqualsWithDelta(3.0, (float) Lancamento::where('recebivel_id', $titulo->id)->value('valor'), 0.001);
        $this->assertStringContainsString('Líquido informado', $titulo->fresh()->observacoes);
    }

    public function test_data_futura_e_recusada(): void
    {
        $this->expectException(FinanceiroException::class);

        $this->service->liquidar($this->recebivel('2'));
    }

    public function test_comando_liquida_so_os_vencidos_e_e_idempotente(): void
    {
        $vencido = $this->recebivel('-1');
        $hoje = $this->recebivel();
        $futuro = $this->recebivel('3');

        $this->artisan('financeiro:liquidar-recebiveis')->assertSuccessful();
        $this->artisan('financeiro:liquidar-recebiveis')->assertSuccessful();

        $this->assertSame(StatusLancamento::Pago, $vencido->fresh()->status);
        $this->assertSame(StatusLancamento::Pago, $hoje->fresh()->status);
        $this->assertSame(StatusLancamento::Pendente, $futuro->fresh()->status);
        $this->assertEqualsWithDelta(2 * 78.8, $this->saldo(), 0.001);
        $this->assertSame(0, MovimentacaoFinanceira::where('data_movimento', '>', today()->toDateString())->count());
    }

    public function test_liquidar_um_titulo_ja_liquidado_e_recusado(): void
    {
        $titulo = $this->recebivel();
        $this->service->liquidar($titulo);

        $this->expectException(FinanceiroException::class);

        $this->service->liquidar($titulo->fresh());
    }

    public function test_estornar_liquidacao_devolve_o_saldo_e_cancela_a_taxa(): void
    {
        $titulo = $this->recebivel();
        $this->service->liquidar($titulo);

        $this->service->estornarLiquidacao($titulo->fresh(), 'Repasse errado');

        $this->assertSame(0.0, $this->saldo());
        $this->assertSame(StatusLancamento::Pendente, $titulo->fresh()->status);
        $this->assertSame(StatusLancamento::Cancelado, Lancamento::where('recebivel_id', $titulo->id)->firstOrFail()->status);
    }

    public function test_conciliado_bloqueia_o_estorno_ate_desmarcar(): void
    {
        $titulo = $this->recebivel();
        $this->service->liquidar($titulo);
        $this->service->conciliar($titulo->fresh());

        try {
            $this->service->estornarLiquidacao($titulo->fresh(), 'Repasse errado');
            $this->fail('Deveria bloquear.');
        } catch (FinanceiroException $e) {
            $this->assertStringContainsString('conciliada', $e->getMessage());
        }

        $this->service->conciliar($titulo->fresh(), false);
        $this->service->estornarLiquidacao($titulo->fresh(), 'Repasse errado');

        $this->assertSame(0.0, $this->saldo());
    }
}
