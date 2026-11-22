<?php

namespace Tests\Feature;

use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Mcp\Servers\GestaoServer;
use App\Mcp\Tools\Gestao\Acoes\BuscarTitulos;
use App\Mcp\Tools\Gestao\Acoes\ConfirmarAcao;
use App\Mcp\Tools\Gestao\Acoes\PrepararBaixaTitulos;
use App\Mcp\Tools\Gestao\Acoes\PrepararDespesa;
use App\Mcp\Tools\Gestao\Acoes\PrepararReceita;
use App\Models\ClaudeAcao;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Roteiro claude-mcp-gestao.md, G3 (ampliação): despesa parcelada, receita avulsa e baixa de títulos pelo chat. */
class ClaudeGestaoFinanceiroTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ContaFinanceira $banco;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-23 15:00:00');
        foreach (['create:lancamento', 'approve:lancamento'] as $nome) {
            Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->banco = ContaFinanceira::factory()->banco()->create(['saldo_atual' => 500]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_despesa_parcelada_em_parcelas_mensais_com_os_centavos_na_primeira(): void
    {
        $plano = PlanoDespesa::create(['nome' => 'Manutenção', 'comportamento' => 'variavel', 'periodicidade' => 'eventual']);

        GestaoServer::actingAs($this->admin)->tool(PrepararDespesa::class, [
            'descricao' => 'Conserto do forno', 'valor' => 1000, 'plano_despesa_id' => $plano->id, 'situacao_pagamento' => 'pago',
            'conta_financeira_id' => $this->banco->id, 'numero_parcelas' => 3,
        ])->assertHasErrors(['não é parcelada']);

        GestaoServer::actingAs($this->admin)->tool(PrepararDespesa::class, [
            'descricao' => 'Conserto do forno', 'valor' => 1000, 'plano_despesa_id' => $plano->id, 'situacao_pagamento' => 'a_pagar',
            'vencimento' => '2026-12-10', 'numero_parcelas' => 3,
        ])->assertOk()->assertSee(['a pagar em 3 parcelas', 'Parcela 3/3: R$']);
        GestaoServer::actingAs($this->admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->sole()->uuid])->assertOk();

        $parcelas = Lancamento::query()->orderBy('parcela_numero')->get();
        $this->assertSame([333.34, 333.33, 333.33], $parcelas->map(fn (Lancamento $l): float => (float) $l->valor)->all());
        $this->assertSame(['2026-12-10', '2027-01-10', '2027-02-10'], $parcelas->map(fn (Lancamento $l): string => $l->vencimento->toDateString())->all());
        $this->assertSame('Conserto do forno — Parcela 2/3', $parcelas[1]->descricao);
        $this->assertSame(3, $parcelas[1]->parcela_total);
    }

    public function test_receita_a_receber_parcelada_e_receita_ja_recebida_entra_na_conta(): void
    {
        $plano = PlanoReceita::create(['nome' => 'Eventos']);
        $cliente = Cliente::create(['cliente_nome' => 'Empresa Alfa', 'cliente_tipo' => 'PJ']);

        GestaoServer::actingAs($this->admin)->tool(PrepararReceita::class, [
            'descricao' => 'Festa de fim de ano', 'valor' => 900, 'plano_receita_id' => $plano->id, 'cliente_id' => $cliente->id,
            'situacao' => 'a_receber', 'parcelas' => [['vencimento' => '2026-12-01', 'valor' => 300], ['vencimento' => '2026-12-20', 'valor' => 600]],
        ])->assertOk()->assertSee(['Empresa Alfa', 'a receber em 2 parcelas']);
        GestaoServer::actingAs($this->admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->sole()->uuid])->assertOk();

        $titulos = Lancamento::query()->where('tipo', TipoLancamento::Receber)->orderBy('vencimento')->get();
        $this->assertCount(2, $titulos);
        $this->assertSame($cliente->id, $titulos[0]->cliente_id);
        $this->assertEquals(600, $titulos[1]->valor);

        GestaoServer::actingAs($this->admin)->tool(PrepararReceita::class, [
            'descricao' => 'Reembolso do seguro', 'valor' => 250, 'plano_receita_id' => $plano->id, 'situacao' => 'recebido',
            'forma_pagamento' => 'transferencia', 'conta_financeira_id' => $this->banco->id,
        ])->assertOk()->assertSee('"pode_confirmar":true');
        GestaoServer::actingAs($this->admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->latest('id')->first()->uuid])->assertOk();

        $this->assertSame(StatusLancamento::Pago, Lancamento::query()->where('descricao', 'Reembolso do seguro')->sole()->status);
        $this->assertEquals(750, $this->banco->fresh()->saldo_atual);
    }

    public function test_baixa_parcial_de_conta_a_pagar_e_recebimento_juntos_na_mesma_conta(): void
    {
        $boleto = $this->titulo(TipoLancamento::Pagar, 'Boleto Moinho', 200);
        $aluguel = $this->titulo(TipoLancamento::Receber, 'Aluguel do salão', 150);

        GestaoServer::actingAs($this->admin)->tool(BuscarTitulos::class, ['busca' => 'Moinho'])
            ->assertOk()->assertSee(['"lancamento_id":'.$boleto->id, '"falta":200'])->assertDontSee('Aluguel do salão');

        GestaoServer::actingAs($this->admin)->tool(PrepararBaixaTitulos::class, [
            'titulos' => [['lancamento_id' => $boleto->id, 'valor' => 120], ['lancamento_id' => $aluguel->id]],
            'forma_pagamento' => 'pix', 'conta_financeira_id' => $this->banco->id,
        ])->assertOk()->assertSee(['pagando R$', 'recebendo R$', 'parcial (falta R$']);

        $this->assertSame(StatusLancamento::Pendente, $boleto->fresh()->status, 'A prévia não grava nada.');
        GestaoServer::actingAs($this->admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->sole()->uuid])->assertOk();

        $this->assertSame(StatusLancamento::Parcial, $boleto->fresh()->status);
        $this->assertSame(StatusLancamento::Pago, $aluguel->fresh()->status);
        $this->assertEquals(530, $this->banco->fresh()->saldo_atual);
    }

    public function test_baixa_bloqueia_titulo_ja_pago_recebimento_acima_do_que_falta_e_saldo_insuficiente(): void
    {
        $pago = $this->titulo(TipoLancamento::Pagar, 'Já pago', 50);
        $pago->registrarPagamento(valor: 50, contaFinanceiraId: $this->banco->id);
        $receber = $this->titulo(TipoLancamento::Receber, 'Evento', 100);
        $caro = $this->titulo(TipoLancamento::Pagar, 'Equipamento', 900);

        GestaoServer::actingAs($this->admin)->tool(PrepararBaixaTitulos::class, [
            'titulos' => [['lancamento_id' => $pago->id], ['lancamento_id' => $receber->id, 'valor' => 120], ['lancamento_id' => $caro->id]],
            'conta_financeira_id' => $this->banco->id,
        ])->assertOk()->assertSee(['"pode_confirmar":false', 'já está Pago', 'passa do que falta receber', 'menos que o valor a pagar']);

        GestaoServer::actingAs($this->admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->sole()->uuid])->assertHasErrors(['alertas']);
    }

    public function test_gerente_sem_registrar_pagamento_nao_ve_a_baixa(): void
    {
        Role::findByName('Gerente', 'web')->givePermissionTo('create:lancamento');
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        GestaoServer::actingAs($gerente)->tools()
            ->assertRegistered([PrepararDespesa::class, PrepararReceita::class])
            ->assertNotRegistered([PrepararBaixaTitulos::class, BuscarTitulos::class]);
    }

    private function titulo(TipoLancamento $tipo, string $descricao, float $valor): Lancamento
    {
        return Lancamento::create([
            'tipo' => $tipo, 'descricao' => $descricao, 'valor' => $valor, 'vencimento' => '2026-11-25', 'status' => StatusLancamento::Pendente,
            $tipo === TipoLancamento::Pagar ? 'plano_despesa_id' : 'plano_receita_id' => $tipo === TipoLancamento::Pagar
                ? PlanoDespesa::firstOrCreate(['nome' => 'Diversos'], ['comportamento' => 'variavel', 'periodicidade' => 'eventual'])->id
                : PlanoReceita::firstOrCreate(['nome' => 'Outras receitas'])->id,
        ]);
    }
}
