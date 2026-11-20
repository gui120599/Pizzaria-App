<?php

namespace Tests\Feature;

use App\Enums\CompraStatusEnum;
use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\StonePedidoStatus;
use App\Enums\TipoRelatorioClaude;
use App\Filament\Resources\ClaudeRelatorios\Pages\ListClaudeRelatorios;
use App\Filament\Resources\ClaudeRelatorios\Pages\ViewClaudeRelatorio;
use App\Mcp\Servers\GestaoServer;
use App\Mcp\Tools\Gestao\Anomalias;
use App\Mcp\Tools\Gestao\RegistrarRelatorio;
use App\Mcp\Tools\Gestao\ResumoDoDia;
use App\Mcp\Tools\Gestao\SugestaoCompras;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ClaudeRelatorio;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\MovimentacaoProduto;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Roteiro claude-mcp-gestao.md, G2: pacotes das rotinas e relatórios do Claude. */
class ClaudeGestaoRotinasTest extends TestCase
{
    use RefreshDatabase;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 08:00:00'); // sábado; "ontem" = sexta 21/11
        foreach (['view:financeiro_dashboard', 'view:relatorio_fechamento_caixa', 'resolver:pendencia_compra', 'view:relatorio_contas_pagar_receber', 'view_any:stone_pedido', 'view_any:produto', 'view_any:compra'] as $nome) {
            Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        }
        Role::findByName('Gerente', 'web')->givePermissionTo(['view:financeiro_dashboard', 'view:relatorio_fechamento_caixa', 'resolver:pendencia_compra', 'view_any:compra']);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Insumos'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_resumo_do_dia_compara_com_a_semana_passada_e_respeita_o_perfil(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $sessao = $this->sessao();
        $this->venda($sessao, 100, '2026-11-21 20:00');
        $this->venda($sessao, 80, '2026-11-14 20:00');

        GestaoServer::actingAs($gerente)->tool(ResumoDoDia::class)
            ->assertOk()
            ->assertSee('"data":"2026-11-21"')
            ->assertSee('"faturamento":100')
            ->assertSee('"variacao_faturamento_pct":25')
            ->assertSee('"pagamentos_a_confirmar":0')
            ->assertDontSee('contas_a_pagar')
            ->assertDontSee('stone_travadas_7_dias');
    }

    public function test_sugestao_de_compras_pelo_consumo_medio_e_pela_ultima_compra(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $farinha = Produto::create(['produto_descricao' => 'Farinha', 'produto_categoria_id' => $this->categoriaId, 'produto_tipo' => 'insumo', 'produto_unidade_estoque' => 'KG', 'produto_controla_estoque' => true, 'produto_saldo_estoque' => 2]);
        $sobrando = Produto::create(['produto_descricao' => 'Sal', 'produto_categoria_id' => $this->categoriaId, 'produto_tipo' => 'insumo', 'produto_unidade_estoque' => 'KG', 'produto_controla_estoque' => true, 'produto_saldo_estoque' => 50]);

        // 28 kg de farinha e 1 kg de sal consumidos em 28 dias.
        $this->saida($farinha, 28, $admin);
        $this->saida($sobrando, 1, $admin);

        $moinho = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'nome' => 'Moinho Bom']);
        $compra = Compra::create(['compra_prestador_id' => $moinho->id, 'compra_data_entrada' => '2026-11-10', 'compra_user_id' => $admin->id]);
        $compra->forceFill(['compra_status' => CompraStatusEnum::CONFIRMADA])->save();
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $farinha->id, 'ci_quantidade_compra' => 2, 'ci_unidade_compra' => 'SC',
            'ci_fator_conversao' => 4, 'ci_custo_unitario_compra' => 40, 'ci_custo_unitario_aquisicao' => 10,
        ]);

        // Cobertura de 7 dias: precisa de 7 kg, tem 2 → comprar 5 kg = 2 sacos de 4 kg, R$ 50 estimados.
        GestaoServer::actingAs($admin)->tool(SugestaoCompras::class)
            ->assertOk()
            ->assertSee('Moinho Bom')
            ->assertSee('"comprar_em_estoque":5')
            ->assertSee('"comprar":2')
            ->assertSee('"unidade_compra":"SC"')
            ->assertSee('"custo_estimado":50')
            ->assertSee('"dias_restantes":2')
            ->assertDontSee('"produto":"Sal"');
    }

    public function test_anomalias_trazem_stone_travada_e_estoque_negativo_e_dizem_o_que_foi_verificado(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        StonePedido::create(['stp_valor_solicitado' => 40, 'stp_status' => StonePedidoStatus::Indeterminado]);
        Produto::create(['produto_descricao' => 'Muçarela', 'produto_categoria_id' => $this->categoriaId, 'produto_tipo' => 'insumo', 'produto_controla_estoque' => true, 'produto_saldo_estoque' => -3]);

        GestaoServer::actingAs($admin)->tool(Anomalias::class)
            ->assertOk()
            ->assertSee('stone_travada')
            ->assertSee('estoque_negativo')
            ->assertSee('Muçarela')
            ->assertSee('diferenças de caixa');
    }

    public function test_registrar_relatorio_grava_e_aparece_no_painel(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        GestaoServer::actingAs($gerente)->tool(RegistrarRelatorio::class, [
            'tipo' => 'resumo_dia',
            'titulo' => 'Resumo de sexta, 21/11',
            'conteudo' => "## Vendas\n- Faturamento **R$ 100,00** (+25% vs sexta passada)",
            'referencia' => '2026-11-21',
        ])->assertOk()->assertSee('relatorios-claude');

        $relatorio = ClaudeRelatorio::query()->sole();
        $this->assertSame(TipoRelatorioClaude::ResumoDia, $relatorio->tipo);
        $this->assertSame($gerente->id, $relatorio->user_id);

        $this->actingAs($gerente);
        Livewire::test(ListClaudeRelatorios::class)->assertCanSeeTableRecords([$relatorio]);
        Livewire::test(ViewClaudeRelatorio::class, ['record' => $relatorio->getKey()])
            ->assertSee('Resumo de sexta, 21/11')
            ->assertSeeHtml('<strong>R$ 100,00</strong>');

        $this->actingAs(User::factory()->create(['name_first' => 'Comum']));
        $this->get(ListClaudeRelatorios::getUrl())->assertForbidden();
    }

    private function saida(Produto $produto, float $quantidade, User $usuario): void
    {
        MovimentacaoProduto::create([
            'mov_produto_id' => $produto->id, 'mov_quantidade' => $quantidade, 'mov_custo_unitario' => 0, 'mov_custo_total' => 0,
            'mov_tipo' => 'SAIDA', 'mov_origem' => MovimentacaoOrigemEnum::VENDA, 'mov_saldo_apos' => 0, 'mov_custo_medio_apos' => 0,
            'mov_data' => now()->subDays(3), 'mov_user_id' => $usuario->id,
        ]);
    }

    private function sessao(): SessaoCaixa
    {
        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subDays(10),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Operador'])->id,
        ]);
    }

    private function venda(SessaoCaixa $sessao, float $total, string $quando): Venda
    {
        return Venda::create([
            'venda_sessao_caixa_id' => $sessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_itens' => $total,
            'venda_valor_total' => $total,
            'venda_datahora_iniciada' => $quando,
            'venda_datahora_finalizada' => $quando,
        ]);
    }
}
