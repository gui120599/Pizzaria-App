<?php

namespace Tests\Feature;

use App\Enums\BaseVencimentoEnum;
use App\Enums\Compras\AutonomiaAgente;
use App\Enums\Compras\OrigemVinculo;
use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\RegraDataEntradaEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Mcp\Servers\ComprasServer;
use App\Mcp\Tools\Compras\BuscarProdutos;
use App\Mcp\Tools\Compras\DetalharCompra;
use App\Mcp\Tools\Compras\HistoricoVinculosFornecedor;
use App\Mcp\Tools\Compras\ListarPagamentosAConfirmar;
use App\Mcp\Tools\Compras\ListarPendencias;
use App\Mcp\Tools\Compras\ProporNovoProduto;
use App\Mcp\Tools\Compras\ProporVinculo;
use App\Mcp\Tools\Compras\RegistrarResumo;
use App\Mcp\Tools\Compras\SugerirLancamento;
use App\Models\AgenteExecucao;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraPendencia;
use App\Models\Empresa;
use App\Models\FornecedorCondicaoCompra;
use App\Models\FornecedorProduto;
use App\Models\Lancamento;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\Compras\CompraImportacaoService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ComprasAgenteMcpTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ_NOVO = '44555666000177';

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        // Chaves do Passport ficam fora do Git (storage/oauth-*.key): cria se faltarem.
        if (! is_file(storage_path('oauth-private.key'))) {
            $this->artisan('passport:keys')->assertSuccessful();
        }
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste LTDA', 'empresa_cnpj' => '11222333000181']);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Insumos'])->id;
    }

    // ── Acesso ───────────────────────────────────────────────────────

    public function test_sem_token_responde_401(): void
    {
        $this->postJson('/mcp/compras', $this->initialize())->assertUnauthorized();
    }

    public function test_usuario_sem_papel_agente_responde_403_inclusive_admin(): void
    {
        $comum = User::factory()->create(['name_first' => 'Comum']);
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);

        Passport::actingAs($comum, [], 'api');
        $this->postJson('/mcp/compras', $this->initialize())->assertForbidden();

        Passport::actingAs($admin, [], 'api');
        $this->postJson('/mcp/compras', $this->initialize())->assertForbidden();
    }

    public function test_agente_conecta_e_nao_entra_no_painel(): void
    {
        $agente = $this->agente();
        Passport::actingAs($agente, [], 'api');

        $this->postJson('/mcp/compras', $this->initialize())->assertOk();
        $this->assertFalse($agente->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_descoberta_oauth_esta_publicada(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp/compras')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp/compras'));
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('registration_endpoint', url('/oauth/register'));
    }

    public function test_servidor_so_expoe_leitura_propostas_e_resumo(): void
    {
        $esperadas = [
            ListarPendencias::class, DetalharCompra::class, BuscarProdutos::class, HistoricoVinculosFornecedor::class,
            ProporVinculo::class, ProporNovoProduto::class, SugerirLancamento::class, ListarPagamentosAConfirmar::class,
            RegistrarResumo::class,
        ];

        ComprasServer::tools()->assertRegistered($esperadas);

        $registradas = (new \ReflectionProperty(ComprasServer::class, 'tools'))->getDefaultValue();
        $this->assertEqualsCanonicalizing($esperadas, $registradas);
        foreach ($registradas as $tool) {
            $this->assertDoesNotMatchRegularExpression('/baixa|estorn|param|exclu|delet|cancel|configur|confirmar_pag/i', (new $tool)->name());
        }
    }

    // ── Tools ────────────────────────────────────────────────────────

    public function test_listar_pendencias_traz_o_contexto_da_nota(): void
    {
        $this->importar();

        ComprasServer::actingAs($this->agente())->tool(ListarPendencias::class, ['tipo' => 'produto_nao_identificado'])
            ->assertOk()
            ->assertSee(['CAIXA PIZZA 35CM PARDA', 'EMB-35', 'Distribuidora Nova Ficticia LTDA']);
    }

    public function test_observacoes_do_fornecedor_chegam_ao_agente(): void
    {
        $pendencia = $this->pendenciaDaCaixa(AutonomiaAgente::Sugerir);
        $orientacao = 'Código EMB-35 é caixa de pizza 35 cm, fator 1.';
        FornecedorCondicaoCompra::query()->update(['fcc_observacoes' => $orientacao]);
        $agente = $this->agente();

        ComprasServer::actingAs($agente)->tool(ListarPendencias::class)->assertOk()->assertSee($orientacao);
        ComprasServer::actingAs($agente)->tool(DetalharCompra::class, ['compra_id' => $pendencia->compra_id])->assertOk()->assertSee($orientacao);
        ComprasServer::actingAs($agente)->tool(HistoricoVinculosFornecedor::class, ['fornecedor_id' => $pendencia->compra->compra_prestador_id])
            ->assertOk()->assertSee($orientacao);
    }

    public function test_propor_vinculo_com_autonomia_vincular_e_confianca_alta_executa(): void
    {
        $agente = $this->agente();
        $pendencia = $this->pendenciaDaCaixa(AutonomiaAgente::Vincular);
        $produto = $this->insumo('Caixa de pizza 35 cm');

        ComprasServer::actingAs($agente)->tool(ProporVinculo::class, [
            'pendencia_id' => $pendencia->id, 'produto_id' => $produto->id, 'fator_conversao' => 1,
            'confianca' => 0.95, 'justificativa' => 'Mesma descrição e unidade.',
        ])->assertOk()->assertSee('executada');

        $this->assertSame(StatusPendencia::Resolvida, $pendencia->fresh()->status);
        $this->assertSame($agente->id, $pendencia->fresh()->resolvida_por);
        $this->assertSame(OrigemVinculo::Agente, FornecedorProduto::where('fp_codigo_fornecedor', 'EMB-35')->sole()->fp_origem);
    }

    public function test_confianca_abaixo_do_limite_fica_sugerida(): void
    {
        $pendencia = $this->pendenciaDaCaixa(AutonomiaAgente::Vincular);
        $produto = $this->insumo('Caixa de pizza 35 cm');

        ComprasServer::actingAs($this->agente())->tool(ProporVinculo::class, [
            'pendencia_id' => $pendencia->id, 'produto_id' => $produto->id, 'fator_conversao' => 1,
            'confianca' => 0.80, 'justificativa' => 'Talvez seja a de 35 cm.',
        ])->assertOk()->assertSee('sugerida');

        $pendencia->refresh();
        $this->assertSame(StatusPendencia::Sugerida, $pendencia->status);
        $this->assertEquals(0.8, $pendencia->confianca);
        $this->assertSame(0, FornecedorProduto::where('fp_codigo_fornecedor', 'EMB-35')->count());
    }

    public function test_autonomia_sugerir_nunca_executa(): void
    {
        $pendencia = $this->pendenciaDaCaixa(AutonomiaAgente::Sugerir);

        ComprasServer::actingAs($this->agente())->tool(ProporVinculo::class, [
            'pendencia_id' => $pendencia->id, 'produto_id' => $this->insumo('Caixa')->id, 'fator_conversao' => 1,
            'confianca' => 0.99, 'justificativa' => 'Certeza.',
        ])->assertOk()->assertSee('sugerida');

        $this->assertSame(StatusPendencia::Sugerida, $pendencia->fresh()->status);
    }

    public function test_produto_novo_com_autonomia_vincular_fica_sugerido_e_com_completa_cadastra_para_revisao(): void
    {
        $pendencia = $this->pendenciaDaCaixa(AutonomiaAgente::Vincular);
        $argumentos = [
            'pendencia_id' => $pendencia->id, 'descricao' => 'Caixa de pizza 35 cm', 'unidade_estoque' => 'un',
            'categoria_id' => $this->categoriaId, 'fator_conversao' => 1, 'confianca' => 0.97, 'justificativa' => 'Nenhuma caixa cadastrada.',
        ];

        ComprasServer::actingAs($this->agente())->tool(ProporNovoProduto::class, $argumentos)->assertOk()->assertSee('sugerida');
        $this->assertSame(0, Produto::where('produto_descricao', 'Caixa de pizza 35 cm')->count());

        FornecedorCondicaoCompra::query()->update(['fcc_autonomia_agente' => AutonomiaAgente::Completa->value]);
        ComprasServer::actingAs($this->agente())->tool(ProporNovoProduto::class, $argumentos)->assertOk()->assertSee('executada');

        $produto = Produto::where('produto_descricao', 'Caixa de pizza 35 cm')->sole();
        $this->assertTrue($produto->produto_cadastro_pendente_revisao);
        $this->assertSame('UN', $produto->produto_unidade_estoque);
    }

    public function test_sugerir_lancamento_sempre_aguarda_aprovacao(): void
    {
        $compra = $this->importar();
        $fornecedorId = $compra->compra_prestador_id;
        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Pagar, 'favorecido_id' => $fornecedorId, 'descricao' => 'À mão',
            'valor' => 180, 'vencimento' => $compra->compra_data_emissao, 'status' => StatusLancamento::Pendente,
        ]);
        $pendencia = CompraPendencia::create([
            'compra_id' => $compra->id, 'tipo' => TipoPendenciaCompra::LancamentoAmbiguo, 'status' => StatusPendencia::Aberta,
            'dados' => ['candidatos' => [['lancamento_id' => $lancamento->id, 'descricao' => 'À mão', 'valor' => 180, 'vencimento' => null, 'status' => 'pendente']]],
        ]);

        ComprasServer::actingAs($this->agente())->tool(SugerirLancamento::class, [
            'pendencia_id' => $pendencia->id, 'lancamento_id' => $lancamento->id, 'confianca' => 0.99, 'justificativa' => 'Mesmo valor e data.',
        ])->assertOk()->assertSee('sugerida');

        $this->assertSame(StatusPendencia::Sugerida, $pendencia->fresh()->status);
        $this->assertNull($lancamento->fresh()->compra_id);
    }

    public function test_tool_com_pendencia_inexistente_devolve_erro_sem_quebrar(): void
    {
        ComprasServer::actingAs($this->agente())->tool(ProporVinculo::class, [
            'pendencia_id' => 999999, 'produto_id' => 1, 'fator_conversao' => 1, 'confianca' => 0.9, 'justificativa' => 'x',
        ])->assertHasErrors(['Registro não encontrado.']);
    }

    public function test_leituras_de_compra_produtos_historico_e_pagamentos(): void
    {
        $compra = $this->importar();
        $agente = $this->agente();
        $this->insumo('Catchup sachê', ['produto_codigo_EAN' => '7891234567895']);

        ComprasServer::actingAs($agente)->tool(DetalharCompra::class, ['compra_id' => $compra->id])->assertOk()->assertSee('EMB-35');
        ComprasServer::actingAs($agente)->tool(BuscarProdutos::class, ['termo' => 'catchup'])->assertOk()->assertSee('Catchup sachê');
        ComprasServer::actingAs($agente)->tool(HistoricoVinculosFornecedor::class, ['fornecedor_id' => $compra->compra_prestador_id])->assertOk();
        ComprasServer::actingAs($agente)->tool(ListarPagamentosAConfirmar::class, ['dias' => 30])->assertOk()->assertSee('valor_total');
    }

    public function test_registrar_resumo_grava_a_rodada(): void
    {
        $agente = $this->agente();

        ComprasServer::actingAs($agente)->tool(RegistrarResumo::class, ['texto' => 'Nada pendente hoje.'])->assertOk();

        $execucao = AgenteExecucao::sole();
        $this->assertSame('Nada pendente hoje.', $execucao->resumo);
        $this->assertSame($agente->id, $execucao->user_id);
    }

    public function test_comando_cria_o_agente_com_o_papel(): void
    {
        $this->artisan('compras:criar-agente')->assertSuccessful();

        $agente = User::where('email', 'agente.compras@sistema.local')->sole();
        $this->assertTrue($agente->hasRole('Agente'));
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function initialize(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'teste', 'version' => '1.0']],
        ];
    }

    private function agente(): User
    {
        $agente = User::factory()->create(['name_first' => 'Agente Compras']);
        $agente->assignRole('Agente');

        return $agente;
    }

    private function importar(): Compra
    {
        return app(CompraImportacaoService::class)->importar(file_get_contents(base_path('tests/Fixtures/nfe-entrada/automacao/item-desconhecido.xml')));
    }

    /** Pendência do item "CAIXA PIZZA 35CM" (EMB-35), com o fornecedor na autonomia dada. */
    private function pendenciaDaCaixa(AutonomiaAgente $autonomia): CompraPendencia
    {
        $fornecedor = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => 'Distribuidora Nova', 'cpf_cnpj' => self::CNPJ_NOVO]);
        FornecedorCondicaoCompra::create([
            'fcc_prestador_id' => $fornecedor->id,
            'fcc_prazo_base' => BaseVencimentoEnum::Entrada,
            'fcc_data_entrada_regra' => RegraDataEntradaEnum::Hoje,
            'fcc_autonomia_agente' => $autonomia,
        ]);

        return $this->importar()->pendencias()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)
            ->whereHas('item', fn ($q) => $q->where('ci_codigo_fornecedor', 'EMB-35'))->sole();
    }

    private function insumo(string $nome, array $attrs = []): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'UN',
            ...$attrs,
        ]);
    }
}
