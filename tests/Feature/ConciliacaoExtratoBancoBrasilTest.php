<?php

namespace Tests\Feature;

use App\Enums\BaseVencimentoEnum;
use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\Financeiro\StatusConciliacaoExtrato;
use App\Enums\FormaPagamento;
use App\Enums\RegraDataEntradaEnum;
use App\Enums\StatusLancamento;
use App\Filament\Resources\ExtratoBancarioItens\Pages\ListExtratoBancarioItens;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraPendencia;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\ExtratoBancarioItem;
use App\Models\FornecedorCondicaoCompra;
use App\Models\FornecedorProduto;
use App\Models\MovimentacaoFinanceira;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\Compras\CompraImportacaoService;
use App\Services\Compras\PendenciaService;
use App\Services\Financeiro\BancoBrasil\ImportacaoExtratoService;
use App\Services\Financeiro\ConciliacaoBoletoService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ConciliacaoExtratoBancoBrasilTest extends TestCase
{
    use RefreshDatabase;

    private ContaFinanceira $bancoBrasil;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 07:30:00');
        config([
            'bancos.banco_brasil.client_id' => 'id-teste',
            'bancos.banco_brasil.client_secret' => 'segredo-teste',
            'bancos.banco_brasil.developer_app_key' => 'chave-app-teste',
            'bancos.banco_brasil.ambiente' => 'homologacao',
        ]);

        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste LTDA', 'empresa_cnpj' => '11222333000181']);
        $this->bancoBrasil = ContaFinanceira::factory()->banco()->comSaldo(10000)->create([
            'nome' => 'Banco do Brasil', 'agencia' => '1234-5', 'conta' => '99999-9', 'integracao' => 'banco_brasil',
        ]);

        $moinho = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => 'Moinho', 'cpf_cnpj' => '33444555000166']);
        FornecedorCondicaoCompra::create([
            'fcc_prestador_id' => $moinho->id,
            'fcc_prazo_base' => BaseVencimentoEnum::Entrada,
            'fcc_data_entrada_regra' => RegraDataEntradaEnum::Hoje,
            'fcc_regra_baixa' => RegraBaixaCompra::PorConciliacao,
            'fcc_conta_financeira_id' => $this->bancoBrasil->id,
            'fcc_forma_pagamento' => FormaPagamento::Boleto,
        ]);
        FornecedorProduto::create([
            'fp_prestador_id' => $moinho->id,
            'fp_produto_id' => Produto::create(['produto_descricao' => 'Farinha', 'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Insumos'])->id, 'produto_tipo' => 'insumo'])->id,
            'fp_codigo_fornecedor' => 'FAR-25',
            'fp_fator_conversao' => 25,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_debito_com_uma_parcela_exata_baixa_na_data_do_extrato(): void
    {
        $compra = $this->importarBoleto();
        $this->fakeExtrato([$this->linha('24112026', 300.00, 'D', 'PAGTO COBRANCA', '771')]);

        $resultado = app(ImportacaoExtratoService::class)->importar($this->bancoBrasil);

        $this->assertSame(1, $resultado['conciliadas']);
        $parcela = $compra->lancamentos()->orderBy('parcela_numero')->first();
        $this->assertSame(StatusLancamento::Pago, $parcela->status);
        $this->assertSame('2026-11-24', $parcela->data_pagamento->toDateString());
        $this->assertNotNull($parcela->pagamentos()->sole()->conciliado_em);

        $item = ExtratoBancarioItem::sole();
        $this->assertSame(StatusConciliacaoExtrato::Conciliado, $item->status_conciliacao);
        $this->assertSame($this->bancoBrasil->id, $item->movimentacao->conta_financeira_id);
        $this->assertSame($parcela->id, $item->movimentacao->lancamento_id);
    }

    public function test_reimportar_o_mesmo_periodo_nao_duplica_e_ignora_linhas_de_saldo(): void
    {
        $this->fakeExtrato([
            $this->linha('22112026', 0, 'C', 'Saldo Anterior'),
            $this->linha('23112026', 50.00, 'C', 'PIX RECEBIDO', '1'),
            $this->linha('23112026', 50.00, 'C', 'PIX RECEBIDO', '1'),
            $this->linha('24112026', 120.00, 'D', 'TARIFA', '2'),
            $this->linha('25112026', 9000.00, 'C', 'S A L D O'),
        ]);

        $primeira = app(ImportacaoExtratoService::class)->importar($this->bancoBrasil);
        $segunda = app(ImportacaoExtratoService::class)->importar($this->bancoBrasil);

        $this->assertSame(3, $primeira['novas']);
        $this->assertSame(0, $segunda['novas']);
        $this->assertSame(3, ExtratoBancarioItem::count());
        $this->assertSame('2026-11-25', $this->bancoBrasil->fresh()->ultimo_extrato_em->toDateString());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/conta-corrente/agencia/12345/conta/999999')
            && $request['gw-dev-app-key'] === 'chave-app-teste'
            && $request['dataInicioSolicitacao'] === '22112026'
            && $request['dataFimSolicitacao'] === '25112026');
    }

    public function test_duas_parcelas_possiveis_marcam_ambiguo_e_abrem_pendencia_que_se_resolve_escolhendo(): void
    {
        $compra = $this->importarBoleto(fn (string $xml) => str_replace('2026-12-08', '2026-11-26', $xml));
        $this->fakeExtrato([$this->linha('24112026', 300.00, 'D', 'PAGTO COBRANCA', '771')]);

        app(ImportacaoExtratoService::class)->importar($this->bancoBrasil);

        $item = ExtratoBancarioItem::sole();
        $this->assertSame(StatusConciliacaoExtrato::Ambiguo, $item->status_conciliacao);
        $this->assertSame(0, MovimentacaoFinanceira::count());
        $pendencia = CompraPendencia::query()->doTipo(TipoPendenciaCompra::PagamentoAConfirmar)->sole();
        $this->assertCount(2, $pendencia->dados['candidatos']);

        $segunda = $compra->lancamentos()->where('parcela_numero', 2)->sole();
        app(PendenciaService::class)->resolverLancamento($pendencia, $segunda->id);

        $this->assertSame(StatusLancamento::Pago, $segunda->fresh()->status);
        $this->assertSame(StatusConciliacaoExtrato::Conciliado, $item->fresh()->status_conciliacao);
        $this->assertSame(StatusPendencia::Resolvida, $pendencia->fresh()->status);
    }

    public function test_valor_com_juros_nunca_baixa_sozinho_e_a_conciliacao_manual_registra_o_valor_pago(): void
    {
        $compra = $this->importarBoleto();
        $this->fakeExtrato([$this->linha('25112026', 305.10, 'D', 'PAGTO COBRANCA', '771')]);

        app(ImportacaoExtratoService::class)->importar($this->bancoBrasil);

        $item = ExtratoBancarioItem::sole();
        $this->assertSame(StatusConciliacaoExtrato::SemCorrespondencia, $item->status_conciliacao);
        $this->assertSame(0, MovimentacaoFinanceira::count());

        $parcela = $compra->lancamentos()->orderBy('parcela_numero')->first();
        app(ConciliacaoBoletoService::class)->conciliarManual($item, $parcela);

        $this->assertSame(StatusLancamento::Pago, $parcela->fresh()->status);
        $this->assertEquals(305.10, MovimentacaoFinanceira::sole()->valor);
    }

    public function test_parcela_baixada_a_mao_antes_do_extrato_so_e_conciliada(): void
    {
        $compra = $this->importarBoleto();
        $parcela = $compra->lancamentos()->orderBy('parcela_numero')->first();
        $parcela->registrarPagamento(300.00, Carbon::parse('2026-11-23'), FormaPagamento::Boleto, contaFinanceiraId: $this->bancoBrasil->id);
        $this->fakeExtrato([$this->linha('24112026', 300.00, 'D', 'PAGTO COBRANCA', '771')]);

        app(ImportacaoExtratoService::class)->importar($this->bancoBrasil);

        $this->assertSame(1, MovimentacaoFinanceira::count());
        $item = ExtratoBancarioItem::sole();
        $this->assertSame(StatusConciliacaoExtrato::Conciliado, $item->status_conciliacao);
        $this->assertSame(MovimentacaoFinanceira::sole()->id, $item->movimentacao_financeira_id);
        $this->assertNotNull($parcela->pagamentos()->sole()->conciliado_em);
    }

    public function test_comando_sem_conta_integrada_nao_faz_nada_e_com_conta_importa(): void
    {
        $this->fakeExtrato([]);
        $this->artisan('financeiro:importar-extrato-bb')->assertSuccessful();
        Http::assertSentCount(2);

        $this->bancoBrasil->update(['integracao' => null]);
        $this->artisan('financeiro:importar-extrato-bb')->expectsOutputToContain('Nenhuma conta')->assertSuccessful();
    }

    public function test_credencial_recusada_vira_erro_legivel(): void
    {
        Http::fake(['oauth.hm.bb.com.br/*' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->artisan('financeiro:importar-extrato-bb')->expectsOutputToContain('recusou a autenticação')->assertFailed();
    }

    public function test_tela_do_extrato_lista_e_concilia_a_mao(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $compra = $this->importarBoleto();
        $this->fakeExtrato([$this->linha('25112026', 305.10, 'D', 'PAGTO COBRANCA', '771')]);
        app(ImportacaoExtratoService::class)->importar($this->bancoBrasil);
        $item = ExtratoBancarioItem::sole();
        $parcela = $compra->lancamentos()->orderBy('parcela_numero')->first();

        Livewire::test(ListExtratoBancarioItens::class)
            ->assertCanSeeTableRecords([$item])
            ->callAction(TestAction::make('conciliar')->table($item), ['lancamento_id' => $parcela->id])
            ->assertHasNoActionErrors();

        $this->assertSame(StatusConciliacaoExtrato::Conciliado, $item->fresh()->status_conciliacao);
        $this->assertSame(StatusLancamento::Pago, $parcela->fresh()->status);
    }

    // ------------------------------------------------------------------

    private function importarBoleto(?callable $ajuste = null): Compra
    {
        $xml = file_get_contents(base_path('tests/Fixtures/nfe-entrada/automacao/boleto-3-duplicatas.xml'));

        return app(CompraImportacaoService::class)->importar($ajuste ? $ajuste($xml) : $xml);
    }

    /** @param  list<array<string, mixed>>  $linhas */
    private function fakeExtrato(array $linhas): void
    {
        Http::fake([
            'oauth.hm.bb.com.br/*' => Http::response(['access_token' => 'token-teste', 'token_type' => 'Bearer', 'expires_in' => 600]),
            'api.hm.bb.com.br/*' => Http::response([
                'numeroPaginaAtual' => 1,
                'quantidadeRegistroPaginaAtual' => count($linhas),
                'numeroPaginaProximo' => 0,
                'quantidadeTotalPagina' => 1,
                'quantidadeTotalRegistro' => count($linhas),
                'listaLancamento' => $linhas,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function linha(string $data, float $valor, string $sinal, string $historico, ?string $documento = null): array
    {
        return [
            'indicadorTipoLancamento' => '1',
            'dataLancamento' => (int) $data,
            'dataMovimento' => 0,
            'codigoAgenciaOrigem' => 0,
            'numeroLote' => 0,
            'numeroDocumento' => $documento,
            'codigoHistorico' => 0,
            'textoDescricaoHistorico' => $historico,
            'valorLancamento' => $valor,
            'indicadorSinalLancamento' => $sinal,
            'textoInformacaoComplementar' => '',
        ];
    }
}
