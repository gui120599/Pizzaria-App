<?php

namespace Tests\Feature;

use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Widgets\FinanceiroStatsOverview;
use App\Mcp\Servers\GestaoServer;
use App\Mcp\Tools\Gestao\ContasPagarReceber;
use App\Mcp\Tools\Gestao\DebitosClientes;
use App\Mcp\Tools\Gestao\FechamentoCaixa;
use App\Mcp\Tools\Gestao\RankingProdutos;
use App\Mcp\Tools\Gestao\ResumoVendas;
use App\Mcp\Tools\Gestao\SaldosContas;
use App\Mcp\Tools\Gestao\SituacaoCaixaAgora;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\ItensVenda;
use App\Models\Lancamento;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\RelatorioFechamentoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Roteiro claude-mcp-gestao.md, G1: conector de gestão só leitura (dono e gerentes). */
class ClaudeGestaoMcpTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 20:00:00');
        if (! is_file(storage_path('oauth-private.key'))) {
            $this->artisan('passport:keys')->assertSuccessful();
        }

        // Permissões das telas que o Gerente tem em produção (criadas pelo Shield).
        foreach (['view:financeiro_dashboard', 'view:relatorio_fechamento_caixa', 'view:relatorio_contas_pagar_receber'] as $nome) {
            Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        }
        Role::findByName('Gerente', 'web')->givePermissionTo(['view:financeiro_dashboard', 'view:relatorio_fechamento_caixa']);

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(3),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Operador'])->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_so_dono_e_gerente_conectam_e_o_agente_e_recusado(): void
    {
        $agente = User::factory()->create(['name_first' => 'Agente Compras']);
        $agente->assignRole('Agente');
        $comum = User::factory()->create(['name_first' => 'Comum']);
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);

        $this->postJson('/mcp/gestao', $this->initialize())->assertUnauthorized();

        foreach ([$agente, $comum] as $usuario) {
            Passport::actingAs($usuario, [], 'api');
            $this->postJson('/mcp/gestao', $this->initialize())->assertForbidden();
        }

        foreach ([$gerente, $admin] as $usuario) {
            Passport::actingAs($usuario, [], 'api');
            $this->postJson('/mcp/gestao', $this->initialize())->assertOk();
        }
    }

    public function test_cada_perfil_ve_so_as_tools_das_telas_que_acessa(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);

        Passport::actingAs($gerente, [], 'api');
        $this->postJson('/mcp/gestao', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk()
            ->assertSee('resumo_vendas')
            ->assertSee('fechamento_caixa')
            ->assertDontSee('contas_a_pagar_receber')
            ->assertDontSee('saldos_contas');

        GestaoServer::actingAs($gerente)->tool(ContasPagarReceber::class)->assertHasErrors();

        Passport::actingAs($admin, [], 'api');
        $this->postJson('/mcp/gestao', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])
            ->assertOk()
            ->assertSee('contas_a_pagar_receber')
            ->assertSee('debitos_clientes');
    }

    public function test_resumo_de_vendas_bate_com_o_painel_financeiro_e_compara_com_o_dia_anterior(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->venda(100);
        $this->venda(50);
        $this->venda(30, Carbon::parse('2026-11-20 21:00'));
        $this->venda(40, status: 'CANCELADA');

        GestaoServer::actingAs($admin)->tool(ResumoVendas::class, ['inicio' => '2026-11-21'])
            ->assertOk()
            ->assertSee('"faturamento":150')
            ->assertSee('"vendas":2')
            ->assertSee('"ticket":75')
            ->assertSee('"canceladas":1')
            ->assertSee('"inicio":"2026-11-20"')
            ->assertSee('"faturamento":400');

        // O widget do painel usa a mesma conta (refatorada para IndicadoresVendas).
        $this->actingAs($admin);
        Livewire::test(FinanceiroStatsOverview::class, ['pageFilters' => ['inicio' => '2026-11-21', 'fim' => '2026-11-21']])
            ->assertSee('R$ 150,00')
            ->assertSee('2 vendas finalizadas');
    }

    public function test_ranking_de_produtos_por_receita(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $calabresa = Produto::create(['produto_descricao' => 'Pizza Calabresa', 'produto_categoria_id' => $categoria->id, 'produto_tipo' => 'produzido']);
        $marguerita = Produto::create(['produto_descricao' => 'Pizza Marguerita', 'produto_categoria_id' => $categoria->id, 'produto_tipo' => 'produzido']);
        $this->item($this->venda(120), $calabresa, 2, 120, 30);
        $this->item($this->venda(55), $marguerita, 1, 55, 20);

        GestaoServer::actingAs($admin)->tool(RankingProdutos::class, ['limite' => 5])
            ->assertOk()
            ->assertSee('Pizza Calabresa')
            ->assertSee('"receita":120')
            ->assertSee('"margem":60');
    }

    public function test_caixa_agora_e_fechamento_usam_os_numeros_do_relatorio(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $this->venda(100);

        GestaoServer::actingAs($gerente)->tool(SituacaoCaixaAgora::class)
            ->assertOk()
            ->assertSee('"sessoes_abertas":1')
            ->assertSee('Caixa 1')
            ->assertSee('"total_vendido":100');

        $resumo = (new RelatorioFechamentoCaixaService(['inicio' => '2026-11-21', 'fim' => '2026-11-21']))->resumo();
        GestaoServer::actingAs($gerente)->tool(FechamentoCaixa::class, ['inicio' => '2026-11-21'])
            ->assertOk()
            ->assertSee('"faturamento":'.json_encode($resumo['faturamento']))
            ->assertSee('conferencia_por_sessao');
    }

    public function test_contas_saldos_e_fiado_para_o_admin_com_telefone_mascarado(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        ContaFinanceira::factory()->cofre()->comSaldo(350)->create();
        $cliente = Cliente::create(['cliente_nome' => 'Maria Fiado', 'cliente_celular' => '62999998877', 'cliente_tipo' => 'Física']);
        Lancamento::create([
            'tipo' => TipoLancamento::Receber, 'descricao' => 'Fiado da Maria', 'cliente_id' => $cliente->id,
            'valor' => 80, 'vencimento' => '2026-11-10', 'status' => StatusLancamento::Pendente,
        ]);
        Lancamento::create([
            'tipo' => TipoLancamento::Pagar, 'descricao' => 'Conta de luz',
            'valor' => 420, 'vencimento' => '2026-11-24', 'status' => StatusLancamento::Pendente,
        ]);

        GestaoServer::actingAs($admin)->tool(ContasPagarReceber::class, ['dias' => 7])
            ->assertOk()
            ->assertSee('Conta de luz')
            ->assertSee('Fiado da Maria')
            ->assertSee('"valor_restante":420');

        GestaoServer::actingAs($admin)->tool(SaldosContas::class)->assertOk()->assertSee('"total":350');

        GestaoServer::actingAs($admin)->tool(DebitosClientes::class)
            ->assertOk()
            ->assertSee('Maria Fiado')
            ->assertSee('8877')
            ->assertDontSee('62999998877')
            ->assertSee('"vencido":80');
    }

    private function venda(float $total, ?Carbon $quando = null, string $status = 'FINALIZADA'): Venda
    {
        $quando ??= now()->subHour();

        return Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_status' => $status,
            'venda_valor_itens' => $total,
            'venda_valor_total' => $total,
            'venda_datahora_iniciada' => $quando,
            'venda_datahora_finalizada' => $status === 'FINALIZADA' ? $quando : null,
        ]);
    }

    private function item(Venda $venda, Produto $produto, float $quantidade, float $valor, float $custoUnitario): void
    {
        ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $venda->id,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => $quantidade,
            'item_venda_quantidade_tributavel' => $quantidade,
            'item_venda_valor_unitario' => $valor / $quantidade,
            'item_venda_custo_unitario' => $custoUnitario,
            'item_venda_valor' => $valor,
            'item_venda_status' => 'INSERIDO',
        ]);
        ItensVenda::query()->where('item_venda_venda_id', $venda->id)->update(['item_venda_custo_unitario' => $custoUnitario]);
    }

    private function initialize(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'teste', 'version' => '1.0']],
        ];
    }
}
