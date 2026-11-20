<?php

namespace Tests\Feature;

use App\Enums\Compras\OrigemVinculo;
use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\StatusRascunhoCompra;
use App\Enums\Compras\TipoChaveVinculo;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\CompraStatusEnum;
use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Filament\Pages\PagamentosAConfirmar;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Mcp\Servers\CompraAvulsaServer;
use App\Mcp\Tools\Compras\Avulsa\BuscarFornecedores;
use App\Mcp\Tools\Compras\Avulsa\ConfirmarCompraAvulsa;
use App\Mcp\Tools\Compras\Avulsa\PrepararCompraAvulsa;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraAnexo;
use App\Models\CompraPendencia;
use App\Models\CompraRascunho;
use App\Models\ContaFinanceira;
use App\Models\FornecedorProduto;
use App\Models\Lancamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacaoProduto;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\Compras\CompraAvulsaService;
use App\Services\Compras\PendenciaService;
use App\Services\FechamentoCaixaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Tests\TestCase;

/** Fase 5 — compras avulsas sem NF-e (spec B §14 e §9.2). */
class CompraAvulsaTest extends TestCase
{
    use RefreshDatabase;

    private int $categoriaId;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        if (! is_file(storage_path('oauth-private.key'))) {
            $this->artisan('passport:keys')->assertSuccessful();
        }
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Insumos'])->id;
        $this->gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preparar_nao_grava_e_confirmar_grava_uma_vez_pago_do_cofre(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->permiteNegativo()->create();
        $sessao = $this->sessaoAberta();
        $calabresa = $this->insumo('Calabresa');
        $servico = app(CompraAvulsaService::class);

        $rascunho = $servico->preparar($this->entrada([
            'conta_financeira_id' => $cofre->id,
            'itens' => [['descricao' => 'Calabresa defumada', 'quantidade' => 5, 'unidade' => 'kg', 'valor_total' => 140, 'produto_id' => $calabresa->id]],
            'valor_total_informado' => 140,
        ]), $this->gerente);

        $this->assertTrue($rascunho->previa['pode_confirmar']);
        $this->assertStringContainsString('Açougue do Zé', $rascunho->previa['resumo_texto']);
        $this->assertSame(0, Compra::count());
        $this->assertSame(0, Lancamento::count());
        $this->assertSame(0, MovimentacaoFinanceira::count());
        $this->assertSame(0, MovimentacaoProduto::count());

        $compra = $servico->confirmar($rascunho->uuid, $this->gerente);
        $denovo = $servico->confirmar($rascunho->uuid, $this->gerente);

        $this->assertSame($compra->id, $denovo->id);
        $this->assertSame(1, Compra::count());
        $this->assertSame(Compra::ORIGEM_AVULSA, $compra->compra_origem);
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertSame($this->gerente->id, $compra->compra_user_id);
        $this->assertEquals(5.0, MovimentacaoProduto::query()->where('mov_produto_id', $calabresa->id)->sole()->mov_quantidade);

        $titulo = $compra->lancamentos()->sole();
        $this->assertSame(StatusLancamento::Pago, $titulo->status);
        $this->assertSame(FormaPagamento::Dinheiro, $titulo->forma_pagamento);
        $this->assertSame('2026-11-20', $titulo->data_pagamento->toDateString());
        $this->assertSame($cofre->id, MovimentacaoFinanceira::sole()->conta_financeira_id);
        $this->assertSame(0, MovimentacoesSessaoCaixa::query()->where('mov_sessaocaixa_id', $sessao->id)->count());
        $this->assertSame(StatusRascunhoCompra::Confirmado, $rascunho->fresh()->status);
    }

    public function test_pago_do_caixa_pdv_com_sessao_aberta_registra_a_retirada_vinculada(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $sessao = $this->sessaoAberta();
        // R$ 500 em vendas em dinheiro na sessão (o esperado na gaveta vem das vendas).
        $this->partialMock(FechamentoCaixaService::class)->shouldReceive('calcularEsperado')
            ->andReturn(['dinheiro' => 500.0, 'debito' => 0.0, 'credito' => 0.0, 'pix' => 0.0, 'pix_cnpj' => 0.0, 'outros' => 0.0]);
        $servico = app(CompraAvulsaService::class);

        $rascunho = $servico->preparar($this->entrada(['conta_financeira_id' => $pdv->id]), $this->gerente);
        $this->assertTrue($rascunho->previa['pode_confirmar'], json_encode($rascunho->previa['alertas']));

        $compra = $servico->confirmar($rascunho->uuid, $this->gerente);

        $movimentacao = MovimentacaoFinanceira::sole();
        $this->assertSame($pdv->id, $movimentacao->conta_financeira_id);
        $retirada = MovimentacoesSessaoCaixa::query()->where('movimentacao_financeira_id', $movimentacao->id)->sole();
        $this->assertSame($sessao->id, $retirada->mov_sessaocaixa_id);
        $this->assertSame(StatusLancamento::Pago, $compra->lancamentos()->sole()->status);
    }

    public function test_caixa_pdv_sem_sessao_aberta_nao_confirma_e_nao_grava_nada(): void
    {
        $pdv = ContaFinanceira::factory()->caixaPdv()->create();
        $servico = app(CompraAvulsaService::class);

        $rascunho = $servico->preparar($this->entrada(['conta_financeira_id' => $pdv->id]), $this->gerente);

        $this->assertFalse($rascunho->previa['pode_confirmar']);
        $this->assertContains('caixa_pdv_fechado', collect($rascunho->previa['alertas'])->pluck('codigo')->all());

        try {
            $servico->confirmar($rascunho->uuid, $this->gerente);
            $this->fail('Confirmou com o caixa fechado.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, Compra::count());
        $this->assertSame(0, Lancamento::count());
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_a_pagar_exige_vencimento_e_gera_titulo_aberto_que_vai_para_pagamentos_a_confirmar_no_vencimento(): void
    {
        $servico = app(CompraAvulsaService::class);

        try {
            $servico->preparar($this->entrada(['situacao_pagamento' => 'a_pagar']), $this->gerente);
            $this->fail('Preparou a pagar sem vencimento.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vencimento', $e->errors());
        }

        $rascunho = $servico->preparar($this->entrada(['situacao_pagamento' => 'a_pagar', 'vencimento' => '2026-11-27']), $this->gerente);
        $compra = $servico->confirmar($rascunho->uuid, $this->gerente);

        $titulo = $compra->lancamentos()->sole();
        $this->assertSame(StatusLancamento::Pendente, $titulo->status);
        $this->assertSame('2026-11-27', $titulo->vencimento->toDateString());
        $this->assertSame(0, MovimentacaoFinanceira::count());
        $this->assertFalse(PagamentosAConfirmar::consulta()->whereKey($titulo->id)->exists());

        Carbon::setTestNow('2026-11-27 09:00:00');
        $this->assertTrue(PagamentosAConfirmar::consulta()->whereKey($titulo->id)->exists());
    }

    public function test_fornecedor_novo_so_nasce_na_confirmacao_como_avulso_e_nome_parecido_alerta(): void
    {
        Prestador::create(['tipo' => 'pf', 'categoria' => 'fornecedor', 'nome' => 'Acougue do ZE']);
        $cofre = ContaFinanceira::factory()->cofre()->permiteNegativo()->create();
        $servico = app(CompraAvulsaService::class);

        $rascunho = $servico->preparar($this->entrada([
            'fornecedor_id' => null,
            'fornecedor_novo' => ['nome' => 'Açougue do Zé', 'telefone' => '62999990000'],
            'conta_financeira_id' => $cofre->id,
        ]), $this->gerente);

        $this->assertContains('fornecedor_semelhante', collect($rascunho->previa['alertas'])->pluck('codigo')->all());
        $this->assertTrue($rascunho->previa['pode_confirmar']);
        $this->assertSame(1, Prestador::count());

        $compra = $servico->confirmar($rascunho->uuid, $this->gerente);

        $novo = $compra->prestador;
        $this->assertTrue($novo->avulso);
        $this->assertNull($novo->cpf_cnpj);
        $this->assertSame('Açougue do Zé', $novo->nome);
        $this->assertSame(2, Prestador::count());
    }

    public function test_produto_informado_ensina_o_de_para_por_descricao_e_a_proxima_compra_reconhece(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->permiteNegativo()->create();
        $fornecedor = $this->fornecedor();
        $bacon = $this->insumo('Bacon');
        $servico = app(CompraAvulsaService::class);

        $primeira = $servico->preparar($this->entrada([
            'fornecedor_id' => $fornecedor->id,
            'conta_financeira_id' => $cofre->id,
            'itens' => [['descricao' => 'Bacon manta (pct 2kg)', 'quantidade' => 3, 'unidade' => 'PCT', 'valor_total' => 105, 'produto_id' => $bacon->id, 'fator_conversao' => 2]],
        ]), $this->gerente);
        $servico->confirmar($primeira->uuid, $this->gerente);

        $vinculo = FornecedorProduto::query()->where('fp_tipo_chave', TipoChaveVinculo::Descricao->value)->sole();
        $this->assertSame('bacon manta pct 2kg', $vinculo->fp_codigo_fornecedor);
        $this->assertSame($bacon->id, $vinculo->fp_produto_id);
        $this->assertSame(OrigemVinculo::Manual, $vinculo->fp_origem);
        $this->assertSame($this->gerente->id, $vinculo->fp_confirmado_por);

        $segunda = $servico->preparar($this->entrada([
            'fornecedor_id' => $fornecedor->id,
            'conta_financeira_id' => $cofre->id,
            'itens' => [['descricao' => 'BACON MANTA  pct 2KG', 'quantidade' => 1, 'unidade' => 'PCT', 'valor_total' => 36]],
        ]), $this->gerente);

        $item = $segunda->previa['itens'][0];
        $this->assertSame($bacon->id, $item['produto_id']);
        $this->assertSame('vinculo_descricao', $item['reconhecimento']);
        $this->assertEquals(2, $item['fator_conversao']);
    }

    public function test_item_nao_identificado_grava_compra_e_pagamento_e_entra_inteira_ao_resolver_a_pendencia(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->permiteNegativo()->create();
        $calabresa = $this->insumo('Calabresa');
        $cebola = $this->insumo('Cebola');
        $servico = app(CompraAvulsaService::class);

        $rascunho = $servico->preparar($this->entrada([
            'conta_financeira_id' => $cofre->id,
            'itens' => [
                ['descricao' => 'Calabresa', 'quantidade' => 2, 'unidade' => 'KG', 'valor_total' => 56, 'produto_id' => $calabresa->id],
                ['descricao' => 'Cebola roxa', 'quantidade' => 3, 'unidade' => 'KG', 'valor_total' => 18],
            ],
        ]), $this->gerente);

        $this->assertTrue($rascunho->previa['pode_confirmar']);
        $this->assertContains('itens_nao_identificados', collect($rascunho->previa['alertas'])->pluck('codigo')->all());

        $compra = $servico->confirmar($rascunho->uuid, $this->gerente);

        $this->assertSame(CompraStatusEnum::RASCUNHO, $compra->compra_status);
        $this->assertSame(StatusLancamento::Pago, $compra->lancamentos()->sole()->status);
        $this->assertSame(0, MovimentacaoProduto::count());
        $pendencia = CompraPendencia::query()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)->sole();

        app(PendenciaService::class)->resolverVinculo($pendencia, $cebola->id, 1, $this->gerente);

        $this->assertSame(StatusPendencia::Resolvida, $pendencia->fresh()->status);
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->fresh()->compra_status);
        $this->assertSame('2026-11-20', $compra->fresh()->compra_data_entrada->toDateString());
        $this->assertSame(2, MovimentacaoProduto::count());
        $this->assertTrue(FornecedorProduto::query()->where('fp_tipo_chave', 'descricao')->where('fp_codigo_fornecedor', 'cebola roxa')->exists());
    }

    public function test_total_divergente_e_duplicidade_aparecem_nos_alertas(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->permiteNegativo()->create();
        $fornecedor = $this->fornecedor();
        $servico = app(CompraAvulsaService::class);

        $divergente = $servico->preparar($this->entrada(['fornecedor_id' => $fornecedor->id, 'conta_financeira_id' => $cofre->id, 'valor_total_informado' => 70]), $this->gerente);
        $this->assertFalse($divergente->previa['pode_confirmar']);
        $this->assertContains('total_divergente', collect($divergente->previa['alertas'])->pluck('codigo')->all());

        $primeira = $servico->preparar($this->entrada(['fornecedor_id' => $fornecedor->id, 'conta_financeira_id' => $cofre->id]), $this->gerente);
        $servico->confirmar($primeira->uuid, $this->gerente);

        $repetida = $servico->preparar($this->entrada(['fornecedor_id' => $fornecedor->id, 'conta_financeira_id' => $cofre->id]), $this->gerente);
        $this->assertContains('possivel_duplicidade', collect($repetida->previa['alertas'])->pluck('codigo')->all());
        $this->assertTrue($repetida->previa['pode_confirmar']);
    }

    public function test_rascunho_de_outro_usuario_ou_expirado_nao_confirma(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->permiteNegativo()->create();
        $servico = app(CompraAvulsaService::class);
        $rascunho = $servico->preparar($this->entrada(['conta_financeira_id' => $cofre->id]), $this->gerente);
        $outro = User::factory()->gerente()->create(['name_first' => 'Outro']);

        try {
            $servico->confirmar($rascunho->uuid, $outro);
            $this->fail('Confirmou rascunho de outro usuário.');
        } catch (AuthorizationException) {
        }

        Carbon::setTestNow(now()->addMinutes(31));
        $this->expectException(ValidationException::class);
        try {
            $servico->confirmar($rascunho->uuid, $this->gerente);
        } finally {
            $this->assertSame(0, Compra::count());
        }
    }

    public function test_agente_e_usuario_sem_permissao_recebem_403_e_gerente_conecta(): void
    {
        $agente = User::factory()->create(['name_first' => 'Agente Compras']);
        $agente->assignRole('Agente');
        $comum = User::factory()->create(['name_first' => 'Comum']);
        $gerente = $this->gerente;

        $this->assertFalse(Gate::forUser($agente)->allows('compras.avulsa'));
        $this->assertFalse(Gate::forUser($agente)->allows('confirmar:pagamento_compra'));
        $this->assertFalse(Gate::forUser($comum)->allows('compras.avulsa'));
        $this->assertTrue(Gate::forUser($gerente)->allows('compras.avulsa'));

        Passport::actingAs($agente, [], 'api');
        $this->postJson('/mcp/compras-avulsa', $this->initialize())->assertForbidden();

        Passport::actingAs($comum, [], 'api');
        $this->postJson('/mcp/compras-avulsa', $this->initialize())->assertForbidden();

        Passport::actingAs($gerente, [], 'api');
        $this->postJson('/mcp/compras-avulsa', $this->initialize())->assertOk();

        // O conector do agente continua sem nenhuma tool de compra avulsa.
        Passport::actingAs($agente, [], 'api');
        $this->postJson('/mcp/compras', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk()
            ->assertDontSee('preparar_compra_avulsa')
            ->assertDontSee('confirmar_compra_avulsa');
    }

    public function test_sequencia_pelo_mcp_gera_uma_compra_um_titulo_pago_e_uma_movimentacao(): void
    {
        $cofre = ContaFinanceira::factory()->cofre()->permiteNegativo()->create();
        $fornecedor = $this->fornecedor();
        $calabresa = $this->insumo('Calabresa');

        CompraAvulsaServer::actingAs($this->gerente)->tool(BuscarFornecedores::class, ['termo' => 'Zé'])
            ->assertOk()->assertSee('Açougue do Zé');

        $resposta = CompraAvulsaServer::actingAs($this->gerente)->tool(PrepararCompraAvulsa::class, $this->entrada([
            'fornecedor_id' => $fornecedor->id,
            'conta_financeira_id' => $cofre->id,
            'itens' => [['descricao' => 'Calabresa', 'quantidade' => 2, 'unidade' => 'KG', 'valor_total' => 60, 'produto_id' => $calabresa->id]],
        ]))->assertOk()->assertSee('pode_confirmar');

        $uuid = CompraRascunho::query()->sole()->uuid;
        $this->assertSame(0, Compra::count());

        CompraAvulsaServer::actingAs($this->gerente)->tool(ConfirmarCompraAvulsa::class, ['rascunho_uuid' => $uuid])
            ->assertOk()->assertSee('link_anexar_comprovante');

        $this->assertSame(1, Compra::query()->where('compra_origem', Compra::ORIGEM_AVULSA)->count());
        $this->assertSame(StatusLancamento::Pago, Lancamento::sole()->status);
        $this->assertSame(1, MovimentacaoFinanceira::count());
        unset($resposta);
    }

    public function test_link_do_comprovante_aceita_imagem_e_expira(): void
    {
        Storage::fake('local');
        $compra = Compra::create(['compra_origem' => Compra::ORIGEM_AVULSA, 'compra_data_entrada' => today(), 'compra_user_id' => $this->gerente->id]);
        $link = app(CompraAvulsaService::class)->linkAnexo($compra);

        $this->get($link)->assertOk()->assertSee('Comprovante da compra #'.$compra->id);
        $this->post($link, ['foto' => UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->post($link, ['foto' => UploadedFile::fake()->image('cupom.jpg', 800, 1200)], ['Accept' => 'application/json'])
            ->assertOk();

        $anexo = CompraAnexo::query()->sole();
        Storage::disk('local')->assertExists($anexo->caminho);
        $this->assertSame($this->gerente->id, $anexo->enviado_por);

        $this->get(URL::temporarySignedRoute('compras.anexo.formulario', now()->subMinute(), ['compra' => $compra->id]))->assertForbidden();
        $this->get('/compras/'.$compra->id.'/comprovante')->assertForbidden();
    }

    public function test_painel_mostra_a_compra_avulsa_filtra_pela_origem_e_exibe_a_entrada_original(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($admin);
        $cofre = ContaFinanceira::factory()->cofre()->permiteNegativo()->create();
        $servico = app(CompraAvulsaService::class);
        $rascunho = $servico->preparar($this->entrada(['conta_financeira_id' => $cofre->id, 'observacao' => 'Reposição de sexta']), $this->gerente);
        $avulsa = $servico->confirmar($rascunho->uuid, $this->gerente);
        $manual = Compra::create(['compra_data_entrada' => today(), 'compra_user_id' => $admin->id]);

        Livewire::test(ListCompras::class)
            ->filterTable('compra_origem', Compra::ORIGEM_AVULSA)
            ->assertCanSeeTableRecords([$avulsa])
            ->assertCanNotSeeTableRecords([$manual]);

        Livewire::test(EditCompra::class, ['record' => $avulsa->getKey()])
            ->assertSee('Compra avulsa (lançada pelo Claude)')
            ->assertSee('Reposição de sexta')
            ->assertSee('Nenhuma foto anexada');
    }

    /** @param  array<string, mixed>  $ajustes */
    private function entrada(array $ajustes = []): array
    {
        if (! array_key_exists('fornecedor_id', $ajustes) && ! array_key_exists('fornecedor_novo', $ajustes)) {
            $ajustes['fornecedor_id'] = $this->fornecedor()->id;
        }

        return array_filter([
            'data_compra' => '2026-11-20',
            'origem_informacao' => 'imagem',
            'itens' => [['descricao' => 'Calabresa defumada', 'quantidade' => 2, 'unidade' => 'KG', 'valor_total' => 60]],
            'situacao_pagamento' => 'pago',
            'forma_pagamento' => 'dinheiro',
            ...$ajustes,
        ], fn (mixed $valor): bool => $valor !== null);
    }

    private function fornecedor(): Prestador
    {
        return Prestador::query()->firstOrCreate(
            ['nome' => 'Açougue do Zé', 'categoria' => 'fornecedor'],
            ['tipo' => 'pf', 'avulso' => true],
        );
    }

    private function insumo(string $nome): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
        ]);
    }

    private function sessaoAberta(): SessaoCaixa
    {
        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHour(),
            'sessaocaixa_saldo_inicial' => 100,
            'sessaocaixa_saldo_final' => 100,
            'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Caixa'])->id,
        ]);
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
