<?php

namespace Tests\Feature;

use App\Mcp\Apps\PainelDoDiaApp;
use App\Mcp\Prompts\Compras\LancarCompraAvulsa;
use App\Mcp\Prompts\Gestao\AnaliseDaSemana;
use App\Mcp\Prompts\Gestao\ContagemDeEstoque;
use App\Mcp\Prompts\Gestao\FechamentoDoDia;
use App\Mcp\Prompts\Gestao\LancarDespesa;
use App\Mcp\Prompts\Gestao\ListaDeCompras;
use App\Mcp\Prompts\Gestao\ProdutoAcabou;
use App\Mcp\Servers\CompraAvulsaServer;
use App\Mcp\Servers\GestaoServer;
use App\Mcp\Tools\Gestao\PainelDoDia;
use App\Models\Caixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Roteiro claude-mcp-gestao.md, G4: atalhos (prompts) e cartão do painel do dia. */
class ClaudeGestaoAtalhosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 21:00:00');
        foreach (['view:financeiro_dashboard', 'update:produto', 'create:movimentacao_balanco', 'create:lancamento', 'view_any:compra'] as $nome) {
            Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        }
        Role::findByName('Gerente', 'web')->givePermissionTo(['view:financeiro_dashboard', 'update:produto', 'create:movimentacao_balanco']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_atalhos_aparecem_conforme_o_perfil(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);

        GestaoServer::actingAs($gerente)->prompts()
            ->assertRegistered([FechamentoDoDia::class, AnaliseDaSemana::class, ProdutoAcabou::class, ContagemDeEstoque::class])
            ->assertNotRegistered([LancarDespesa::class, ListaDeCompras::class]);
        GestaoServer::actingAs($gerente)->prompt(LancarDespesa::class)->assertHasErrors();

        GestaoServer::actingAs($admin)->prompts()->assertRegistered([LancarDespesa::class, ListaDeCompras::class]);
        CompraAvulsaServer::actingAs($admin)->prompts()->assertRegistered(LancarCompraAvulsa::class);
    }

    public function test_atalho_monta_o_roteiro_com_os_argumentos(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        GestaoServer::actingAs($gerente)->prompt(ProdutoAcabou::class, ['produto' => 'calabresa', 'ate' => 'amanhã 18h'])
            ->assertOk()
            ->assertSee(['Acabou "calabresa"', 'até amanhã 18h', 'preparar_pausa_produto', 'confirmar_acao']);

        GestaoServer::actingAs($gerente)->prompt(FechamentoDoDia::class)
            ->assertOk()->assertSee(['fechamento do dia "ontem"', 'resumo_do_dia']);
    }

    public function test_painel_do_dia_traz_os_dados_de_hoje_e_aponta_para_o_cartao(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(4),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $gerente->id,
        ]);
        Venda::create([
            'venda_sessao_caixa_id' => $sessao->id, 'venda_status' => 'FINALIZADA', 'venda_valor_itens' => 150, 'venda_valor_total' => 150,
            'venda_datahora_iniciada' => '2026-11-24 20:00', 'venda_datahora_finalizada' => '2026-11-24 20:10',
        ]);

        GestaoServer::actingAs($gerente)->tool(PainelDoDia::class)
            ->assertOk()
            ->assertSee(['"data":"2026-11-24"', '"hoje":"2026-11-24"', '"faturamento":150'])
            ->assertDontSee('contas_a_pagar');
        GestaoServer::actingAs($gerente)->tool(PainelDoDia::class, ['data' => '2026-11-25'])->assertHasErrors();

        $listagem = (new PainelDoDia)->toArray();
        $this->assertSame((new PainelDoDiaApp)->uri(), $listagem['_meta']['ui']['resourceUri']);

        GestaoServer::actingAs($gerente)->resource(PainelDoDiaApp::class)
            ->assertOk()
            ->assertSee(['createMcpApp', "name: 'painel_do_dia'", 'Pontos de atenção']);
    }
}
