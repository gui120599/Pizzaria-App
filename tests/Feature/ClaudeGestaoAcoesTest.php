<?php

namespace Tests\Feature;

use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\Gestao\StatusAcaoClaude;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Resources\ClaudeAcoes\Pages\ViewClaudeAcao;
use App\Mcp\Servers\GestaoServer;
use App\Mcp\Tools\Gestao\Acoes\ConfirmarAcao;
use App\Mcp\Tools\Gestao\Acoes\PrepararAprovacaoSugestoes;
use App\Mcp\Tools\Gestao\Acoes\PrepararContagemEstoque;
use App\Mcp\Tools\Gestao\Acoes\PrepararDespesa;
use App\Mcp\Tools\Gestao\Acoes\PrepararPausaProduto;
use App\Mcp\Tools\Gestao\Acoes\PrepararPromocao;
use App\Models\Categoria;
use App\Models\ClaudeAcao;
use App\Models\Compra;
use App\Models\CompraPendencia;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\MovimentacaoBalanco;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Roteiro claude-mcp-gestao.md, G3: ações preparadas pelo chat e confirmadas pelo usuário. */
class ClaudeGestaoAcoesTest extends TestCase
{
    use RefreshDatabase;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-23 15:00:00');
        foreach (['update:produto', 'view_any:produto', 'update:promocao_relampago', 'resolver:pendencia_compra', 'create:movimentacao_balanco', 'create:lancamento', 'approve:lancamento'] as $nome) {
            Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        }
        Role::findByName('Gerente', 'web')->givePermissionTo(['update:produto', 'view_any:produto', 'create:movimentacao_balanco']);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Pizzas'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_acoes_aparecem_conforme_o_perfil_e_somem_sem_a_permissao_de_acoes(): void
    {
        if (! is_file(storage_path('oauth-private.key'))) {
            $this->artisan('passport:keys')->assertSuccessful();
        }
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        Passport::actingAs($gerente, [], 'api');
        $this->postJson('/mcp/gestao', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->assertSee('preparar_pausa_produto')
            ->assertSee('preparar_contagem_estoque')
            ->assertSee('confirmar_acao')
            ->assertDontSee('preparar_despesa')
            ->assertDontSee('preparar_promocao');

        Role::findByName('Gerente', 'web')->revokePermissionTo('executar_acoes:claude_gestao');
        $this->postJson('/mcp/gestao', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk()
            ->assertSee('produtos_pausados')
            ->assertDontSee('preparar_pausa_produto')
            ->assertDontSee('confirmar_acao');
    }

    public function test_pausa_desliga_os_canais_so_na_confirmacao_e_religa_no_horario(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $calabresa = $this->produto('Pizza Calabresa Grande');

        $resposta = GestaoServer::actingAs($gerente)->tool(PrepararPausaProduto::class, [
            'acao' => 'pausar', 'busca' => 'Calabresa', 'ate' => '2026-11-24 18:00', 'motivo' => 'Acabou a calabresa',
        ])->assertOk()->assertSee('"pode_confirmar":true')->assertSee('sai de cardápio online e atendimento/garçom');

        $this->assertTrue((bool) $calabresa->fresh()->produto_cardapio, 'A prévia não pode alterar nada.');

        $uuid = ClaudeAcao::query()->sole()->uuid;
        GestaoServer::actingAs($gerente)->tool(ConfirmarAcao::class, ['acao_uuid' => $uuid])->assertOk()->assertSee('volta_em');

        $calabresa->refresh();
        $this->assertFalse((bool) $calabresa->produto_cardapio);
        $this->assertFalse((bool) $calabresa->produto_cardapio_garcom);

        $this->artisan('produtos:retomar-pausas')->assertSuccessful();
        $this->assertFalse((bool) $calabresa->fresh()->produto_cardapio, 'Antes do horário continua pausado.');

        Carbon::setTestNow('2026-11-24 18:01:00');
        $this->artisan('produtos:retomar-pausas')->assertSuccessful();
        $calabresa->refresh();
        $this->assertTrue((bool) $calabresa->produto_cardapio);
        $this->assertTrue((bool) $calabresa->produto_cardapio_garcom);
    }

    public function test_so_quem_preparou_confirma_dentro_da_validade_e_confirmar_de_novo_nao_repete(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $outro = User::factory()->gerente()->create(['name_first' => 'Outro']);
        $produto = $this->produto('Pastel de Carne');

        GestaoServer::actingAs($gerente)->tool(PrepararPausaProduto::class, ['acao' => 'pausar', 'produto_ids' => [$produto->id], 'canais' => ['online']])->assertOk();
        $uuid = ClaudeAcao::query()->sole()->uuid;

        GestaoServer::actingAs($outro)->tool(ConfirmarAcao::class, ['acao_uuid' => $uuid])->assertHasErrors(['outro usuário']);
        $this->assertTrue((bool) $produto->fresh()->produto_cardapio);

        GestaoServer::actingAs($gerente)->tool(ConfirmarAcao::class, ['acao_uuid' => $uuid])->assertOk();
        GestaoServer::actingAs($gerente)->tool(ConfirmarAcao::class, ['acao_uuid' => $uuid])->assertOk();
        $this->assertFalse((bool) $produto->fresh()->produto_cardapio);
        $this->assertTrue((bool) $produto->fresh()->produto_cardapio_garcom, 'Só o canal pedido sai.');
        $this->assertDatabaseCount('produto_pausas', 1);

        GestaoServer::actingAs($gerente)->tool(PrepararPausaProduto::class, ['acao' => 'retomar', 'produto_ids' => [$produto->id]])->assertOk();
        $retomar = ClaudeAcao::query()->where('status', StatusAcaoClaude::Aberta)->sole();
        Carbon::setTestNow(now()->addMinutes(31));
        GestaoServer::actingAs($gerente)->tool(ConfirmarAcao::class, ['acao_uuid' => $retomar->uuid])->assertHasErrors(['expirou']);
        $this->assertSame(StatusAcaoClaude::Expirada, $retomar->fresh()->status);
        $this->assertFalse((bool) $produto->fresh()->produto_cardapio);
    }

    public function test_promocao_liga_na_janela_pedida_e_encerra(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Calabresa da Tarde', 'promocao_ativa' => false, 'promocao_recorrente' => false,
            'promocao_inicio' => '2026-11-01 18:00', 'promocao_fim' => '2026-11-01 20:00', 'promocao_qtd_total' => 20, 'promocao_qtd_vendida' => 20,
        ]);
        $promocao->produtos()->attach($this->produto('Pizza Calabresa Média')->id, ['prp_preco_promocional' => 39.9, 'prp_qtd_vendida' => 20]);

        GestaoServer::actingAs($admin)->tool(PrepararPromocao::class, ['acao' => 'ativar', 'busca' => 'Calabresa'])->assertHasErrors(['fim']);

        GestaoServer::actingAs($admin)->tool(PrepararPromocao::class, [
            'acao' => 'ativar', 'promocao_id' => $promocao->id, 'inicio' => '2026-11-23 18:00', 'fim' => '2026-11-23 20:00', 'zerar_vendidos' => true,
        ])->assertOk()->assertSee('"pode_confirmar":true')->assertSee('Zera os vendidos');
        GestaoServer::actingAs($admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->sole()->uuid])->assertOk();

        $promocao->refresh();
        $this->assertTrue($promocao->promocao_ativa);
        $this->assertFalse($promocao->vigente());
        $this->assertTrue($promocao->vigente(Carbon::parse('2026-11-23 19:00')));
        $this->assertEquals(0, $promocao->promocao_qtd_vendida);
        $this->assertEquals(0, $promocao->promocaoProdutos()->sole()->prp_qtd_vendida);

        GestaoServer::actingAs($admin)->tool(PrepararPromocao::class, ['acao' => 'encerrar', 'promocao_id' => $promocao->id])->assertOk();
        GestaoServer::actingAs($admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->latest('id')->first()->uuid])->assertOk();
        $this->assertFalse($promocao->fresh()->promocao_ativa);
    }

    public function test_aprova_a_sugestao_do_agente_como_na_tela_de_pendencias(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $fornecedor = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'nome' => 'Atacado Bom']);
        $compra = Compra::create(['compra_prestador_id' => $fornecedor->id, 'compra_data_entrada' => '2026-11-20', 'compra_user_id' => $admin->id]);
        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Pagar, 'favorecido_id' => $fornecedor->id, 'descricao' => 'Boleto', 'valor' => 180,
            'vencimento' => '2026-11-30', 'status' => StatusLancamento::Pendente,
        ]);
        $pendencia = CompraPendencia::create([
            'compra_id' => $compra->id, 'tipo' => TipoPendenciaCompra::LancamentoAmbiguo, 'status' => StatusPendencia::Sugerida,
            'dados' => ['candidatos' => [['lancamento_id' => $lancamento->id]]],
            'sugestao' => ['acao' => 'lancamento', 'lancamento_id' => $lancamento->id], 'confianca' => 0.95, 'justificativa' => 'Mesmo valor.',
        ]);

        GestaoServer::actingAs($admin)->tool(PrepararAprovacaoSugestoes::class, ['todas' => true])
            ->assertOk()->assertSee("Lançamento #{$lancamento->id}");
        GestaoServer::actingAs($admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->sole()->uuid])
            ->assertOk()->assertSee('"aprovadas":['.$pendencia->id.']');

        $this->assertSame(StatusPendencia::Resolvida, $pendencia->fresh()->status);
        $this->assertSame($compra->id, $lancamento->fresh()->compra_id);
    }

    public function test_contagem_vira_balanco_e_bloqueia_produto_sem_controle_de_estoque(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $mucarela = $this->produto('Muçarela', ['produto_tipo' => 'insumo', 'produto_controla_estoque' => true, 'produto_saldo_estoque' => 10, 'produto_custo_medio' => 30, 'produto_unidade_estoque' => 'KG']);
        $semControle = $this->produto('Orégano', ['produto_tipo' => 'insumo', 'produto_controla_estoque' => false]);

        GestaoServer::actingAs($gerente)->tool(PrepararContagemEstoque::class, [
            'itens' => [['produto_id' => $mucarela->id, 'quantidade' => 7], ['produto_id' => $semControle->id, 'quantidade' => 1]],
        ])->assertOk()->assertSee('"pode_confirmar":false')->assertSee('não controla estoque');
        GestaoServer::actingAs($gerente)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->sole()->uuid])->assertHasErrors(['alertas']);
        $this->assertEquals(10, $mucarela->fresh()->produto_saldo_estoque);

        GestaoServer::actingAs($gerente)->tool(PrepararContagemEstoque::class, [
            'acao_uuid' => ClaudeAcao::query()->sole()->uuid,
            'itens' => [['busca' => 'Muçarela', 'quantidade' => 7]],
        ])->assertOk()->assertSee('"valor_faltas":90');
        GestaoServer::actingAs($gerente)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->sole()->uuid])->assertOk();

        $this->assertEquals(7, $mucarela->fresh()->produto_saldo_estoque);
        $balanco = MovimentacaoBalanco::query()->sole();
        $this->assertSame($gerente->id, $balanco->mbal_usuario_id);
        $this->assertEquals(3, $balanco->mbal_quantidade_ajuste);
    }

    public function test_despesa_a_pagar_e_paga_pela_conta_e_documento_repetido_bloqueia(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $plano = PlanoDespesa::create(['nome' => 'Energia elétrica', 'comportamento' => 'fixo', 'periodicidade' => 'mensal']);
        $banco = ContaFinanceira::factory()->banco()->create(['saldo_atual' => 1000]);
        $linha = '83600000001-1 23450000000-0 00000000000-0 00000000000-0';

        GestaoServer::actingAs($admin)->tool(PrepararDespesa::class, [
            'descricao' => 'Energia — outubro', 'valor' => 350.25, 'plano_despesa_id' => $plano->id,
            'favorecido_novo' => ['nome' => 'Equatorial Energia'], 'situacao_pagamento' => 'a_pagar',
            'vencimento' => '2026-11-28', 'competencia' => '2026-10', 'numero_documento' => $linha,
        ])->assertOk()->assertSee('"pode_confirmar":true')->assertSee('(será cadastrado)');
        GestaoServer::actingAs($admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->sole()->uuid])->assertOk();

        $titulo = Lancamento::query()->sole();
        $this->assertSame(StatusLancamento::Pendente, $titulo->status);
        $this->assertSame('2026-10-01', $titulo->competencia->toDateString());
        $this->assertSame('Equatorial Energia', $titulo->favorecido->nome);
        $this->assertTrue((bool) $titulo->favorecido->avulso);

        GestaoServer::actingAs($admin)->tool(PrepararDespesa::class, [
            'descricao' => 'Energia — outubro', 'valor' => 350.25, 'plano_despesa_id' => $plano->id,
            'situacao_pagamento' => 'a_pagar', 'vencimento' => '2026-11-28', 'numero_documento' => $linha,
        ])->assertOk()->assertSee('"pode_confirmar":false')->assertSee('com este número de documento');

        GestaoServer::actingAs($admin)->tool(PrepararDespesa::class, [
            'descricao' => 'Gás — botijão', 'valor' => 120, 'plano_despesa_id' => $plano->id,
            'situacao_pagamento' => 'pago', 'forma_pagamento' => 'pix', 'conta_financeira_id' => $banco->id,
        ])->assertOk()->assertSee('"pode_confirmar":true');
        GestaoServer::actingAs($admin)->tool(ConfirmarAcao::class, ['acao_uuid' => ClaudeAcao::query()->latest('id')->first()->uuid])->assertOk()->assertSee('Pago');

        $this->assertSame(StatusLancamento::Pago, Lancamento::query()->where('descricao', 'Gás — botijão')->sole()->status);
        $this->assertEquals(880, $banco->fresh()->saldo_atual);
    }

    public function test_tela_da_acao_mostra_o_resumo_em_linhas_e_escapa_o_texto(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $acao = ClaudeAcao::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $gerente->id, 'tipo' => 'despesa', 'entrada' => [],
            'previa' => ['resumo_texto' => "Energia — outubro\nFavorecido: <b>Equatorial</b>", 'pode_confirmar' => true, 'alertas' => []],
            'status' => StatusAcaoClaude::Confirmada, 'expira_em' => now()->addMinutes(30), 'resultado' => ['lancamento_id' => 912],
        ]);

        $this->actingAs($gerente);
        Livewire::test(ViewClaudeAcao::class, ['record' => $acao->getKey()])
            ->assertSeeHtml('Energia — outubro<br />')
            ->assertSeeHtml('&lt;b&gt;Equatorial&lt;/b&gt;')
            ->assertDontSeeHtml('<b>Equatorial</b>')
            ->assertSeeHtml('<pre class="text-xs whitespace-pre-wrap font-mono">')
            ->assertSee('lancamento_id');
    }

    /** @param  array<string, mixed>  $attrs */
    private function produto(string $nome, array $attrs = []): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'produzido',
            'produto_cardapio' => true,
            'produto_cardapio_garcom' => true,
            'produto_preco_venda' => 59.9,
            ...$attrs,
        ]);
    }
}
