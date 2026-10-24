<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Vendas\Pages\ListVendas;
use App\Filament\Resources\Vendas\Tables\Actions\CancelarVendasEmLoteBulkAction;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\ItensVenda;
use App\Models\NfEmissao;
use App\Models\NfNumeracao;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\RelatorioVendasExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Filtros, abas por situação da NFC-e, totais e ações em lote da tabela de vendas.
 */
class VendasTabelaTest extends TestCase
{
    use RefreshDatabase;

    private const URL_EMISSAO = 'api.nfse.io/v2/companies/company-teste/consumerinvoices';

    private User $admin;

    private SessaoCaixa $sessao;

    private OpcoesPagamento $pix;

    private OpcoesPagamento $cartao;

    private Produto $produto;

    private NfNumeracao $numeracao;

    private int $numero = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name_first' => 'Ana', 'name' => 'Ana']);
        $this->actingAs($this->admin);

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);
        $this->numeracao = NfNumeracao::create(['emitente_cnpj' => '11222333000181', 'ambiente' => 'homologacao', 'modelo' => 65, 'serie' => 1, 'ultimo_numero' => 0]);

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(3),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $this->admin->id,
        ]);

        $this->pix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_desc_nfe' => 'InstantPayment', 'opcaopag_envio_automatico_nfe' => true]);
        $this->cartao = OpcoesPagamento::create(['opcaopag_nome' => 'Cartão Stone', 'opcaopag_desc_nfe' => 'creditCard', 'opcaopag_envio_automatico_nfe' => false]);

        $categoria = Categoria::create(['categoria_nome' => 'Geral']);
        $this->produto = Produto::create(['produto_descricao' => 'Calabresa', 'produto_categoria_id' => $categoria->id, 'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value, 'produto_preco_venda' => 40, 'produto_codigo_NCM' => '19059090', 'produto_CFOP' => '5102', 'produto_CSOSN' => '102', 'produto_unidade_comercial' => 'UN']);

        // Marca o início do controle de emissão (nf_emissoes): antes dele nenhuma venda é "pendente".
        $inicial = $this->venda(['venda_status_nfe' => 'Issued', 'venda_id_nfe' => 'inv-inicial', 'venda_datahora_finalizada' => now()->subDays(40)], statusEmissao: NfEmissao::STATUS_AUTORIZADA);
        NfEmissao::where('venda_id', $inicial->id)->update(['created_at' => now()->subDays(40)]);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function venda(array $dados = [], ?OpcoesPagamento $forma = null, float $total = 40, ?string $statusEmissao = null): Venda
    {
        $venda = Venda::create(array_merge([
            'venda_status' => 'FINALIZADA',
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_valor_itens' => $total,
            'venda_valor_total' => $total,
            'venda_valor_pago' => $total,
            'venda_datahora_iniciada' => now()->subHour(),
            'venda_datahora_finalizada' => now()->subMinutes(30),
        ], $dados));

        ItensVenda::create(['item_numero' => 1, 'item_venda_venda_id' => $venda->id, 'item_venda_produto_id' => $this->produto->id, 'item_venda_quantidade' => 1, 'item_venda_quantidade_tributavel' => 1, 'item_venda_valor_unitario' => $total, 'item_venda_valor' => $total, 'item_venda_status' => 'INSERIDO']);

        if ($forma) {
            PagamentosVenda::create(['pg_venda_venda_id' => $venda->id, 'pg_venda_opcaopagamento_id' => $forma->id, 'pg_venda_valor_pagamento' => $total, 'pg_venda_valor_pago_pelo_cliente' => $total, 'pg_venda_valor_troco' => 0]);
        }

        if ($statusEmissao) {
            NfEmissao::create(['venda_id' => $venda->id, 'numeracao_id' => $this->numeracao->id, 'modelo' => 65, 'serie' => 1, 'numero' => ++$this->numero, 'status' => $statusEmissao, 'decisao_envio' => NfEmissao::DECISAO_MANUAL, 'erro_envio' => $statusEmissao === NfEmissao::STATUS_FALHA_ENVIO ? 'Rejeição 999' : null]);
        }

        return $venda;
    }

    public function test_situacoes_da_nfce(): void
    {
        $pendente = $this->venda([], $this->pix);
        $semNotaNaoExigida = $this->venda([], $this->cartao);
        $falhaStatus = $this->venda(['venda_status_nfe' => 'Error', 'venda_id_nfe' => 'a']);
        $falhaEnvio = $this->venda([], $this->pix, statusEmissao: NfEmissao::STATUS_FALHA_ENVIO);
        $andamento = $this->venda(['venda_status_nfe' => 'Processing', 'venda_id_nfe' => 'b']);
        $autorizada = $this->venda(['venda_status_nfe' => 'Issued', 'venda_id_nfe' => 'c']);
        $cancelada = $this->venda(['venda_status_nfe' => 'Cancelled', 'venda_id_nfe' => 'd']);
        $antiga = $this->venda(['venda_datahora_finalizada' => now()->subDays(90)], $this->pix);

        $ids = fn (string $situacao): array => Venda::situacaoNfe($situacao)->pluck('id')->sort()->values()->all();

        $this->assertSame([$pendente->id], $ids('pendente'));
        $this->assertContains($antiga->id, $ids('sem_nota'));
        $this->assertNotContains($antiga->id, $ids('pendente'));
        $this->assertContains($semNotaNaoExigida->id, $ids('sem_nota'));
        $this->assertSame([$falhaStatus->id, $falhaEnvio->id], $ids('falha'));
        $this->assertSame([$andamento->id], $ids('andamento'));
        $this->assertContains($autorizada->id, $ids('autorizada'));
        $this->assertSame([$cancelada->id], $ids('cancelada'));
    }

    public function test_tabela_sem_filtros_lista_todas_e_cada_filtro_recorta(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Maria', 'cliente_tipo' => 'PF']);
        $a = $this->venda(['venda_cliente_id' => $cliente->id, 'venda_valor_desconto' => 5], $this->pix, 100);
        $b = $this->venda([], $this->cartao, 20);
        $outraSessao = SessaoCaixa::create(['sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 2'])->id, 'sessaocaixa_status' => 'ABERTA', 'sessaocaixa_data_hora_abertura' => now(), 'sessaocaixa_saldo_inicial' => 0, 'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Beto', 'name' => 'Beto'])->id]);
        $c = $this->venda(['venda_sessao_caixa_id' => $outraSessao->id, 'venda_datahora_finalizada' => now()->subDays(3), 'venda_valor_pago' => 0], $this->pix, 55);

        // Sem filtro nenhum a tabela não pode zerar (gotcha dos filtros fora do filtersFormSchema).
        $tabela = Livewire::test(ListVendas::class)->assertCanSeeTableRecords([$a, $b, $c]);

        $tabela->filterTable('forma_pagamento', [$this->cartao->id])->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])->removeTableFilter('forma_pagamento');
        $tabela->filterTable('caixa_id', $outraSessao->sessaocaixa_caixa_id)->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b])->removeTableFilter('caixa_id');
        $tabela->filterTable('operador_id', $this->admin->id)->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$c])->removeTableFilter('operador_id');
        $tabela->filterTable('sessao_caixa', ['sessao_id' => $outraSessao->id])->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b])->removeTableFilter('sessao_caixa');
        $tabela->filterTable('cliente', ['cliente_id' => $cliente->id])->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c])->removeTableFilter('cliente');
        $tabela->filterTable('sem_cliente', true)->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a])->removeTableFilter('sem_cliente');
        $tabela->filterTable('com_desconto', true)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c])->removeTableFilter('com_desconto');
        $tabela->filterTable('com_fiado', true)->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b])->removeTableFilter('com_fiado');
        $tabela->filterTable('periodo', ['de' => now()->subDay()->toDateString(), 'ate' => now()->toDateString()])->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$c])->removeTableFilter('periodo');
        $tabela->filterTable('valor', ['valor_min' => '50,00', 'valor_max' => '120,00'])->assertCanSeeTableRecords([$a, $c])->assertCanNotSeeTableRecords([$b])->removeTableFilter('valor');
        $tabela->filterTable('situacao_nfe', 'pendente')->assertCanSeeTableRecords([$a, $c])->assertCanNotSeeTableRecords([$b])->removeTableFilter('situacao_nfe');
    }

    public function test_abas_mostram_so_a_situacao_e_o_total_segue_o_filtro(): void
    {
        $pendente = $this->venda([], $this->pix, 30);
        $falha = $this->venda(['venda_status_nfe' => 'Error', 'venda_id_nfe' => 'a'], null, 70);
        $autorizada = $this->venda(['venda_status_nfe' => 'Issued', 'venda_id_nfe' => 'c'], null, 10);

        Livewire::test(ListVendas::class)
            ->set('activeTab', 'pendentes')
            ->assertCanSeeTableRecords([$pendente])->assertCanNotSeeTableRecords([$falha, $autorizada])
            ->assertTableColumnSummarySet('venda_valor_total', 'sum', 30)
            ->set('activeTab', 'falha')
            ->assertCanSeeTableRecords([$falha])->assertCanNotSeeTableRecords([$pendente, $autorizada])
            ->assertTableColumnSummarySet('venda_valor_total', 'sum', 70)
            ->set('activeTab', 'todas')
            ->assertTableColumnSummarySet('venda_valor_total', 'sum', 150);
    }

    public function test_envio_em_lote_pula_bloqueadas_e_segue_apos_falha(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::URL_EMISSAO => Http::sequence()
                ->push(['message' => 'Rejeição 999'], 400)
                ->push(['id' => 'inv-ok', 'status' => 'Processing']),
            self::URL_EMISSAO.'/inv-ok' => Http::response(['status' => 'Processing']),
        ]);
        $falha = $this->venda([], $this->pix);
        $ok = $this->venda([], $this->pix);
        $autorizada = $this->venda(['venda_status_nfe' => 'Issued', 'venda_id_nfe' => 'c']);

        Livewire::test(ListVendas::class)
            ->callTableBulkAction('enviarNfeEmLote', [$falha, $ok, $autorizada]);

        // A tabela ordena por id desc: a 1ª da fila (a mais nova) leva a recusa e a outra é enviada.
        $this->assertSame(NfEmissao::STATUS_FALHA_ENVIO, $ok->fresh()->nfEmissaoAtual->status);
        $this->assertSame('inv-ok', $falha->fresh()->venda_id_nfe);
        $this->assertSame('Issued', $autorizada->fresh()->venda_status_nfe);
        Http::assertSentCount(3);
    }

    public function test_atualizar_status_em_lote_consulta_so_as_pendentes_de_confirmacao(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.nfse.io/v2/companies/company-teste/consumerinvoices/inv-p' => Http::response(['status' => 'Issued'])]);
        $parada = $this->venda(['venda_id_nfe' => 'inv-p']);
        $autorizada = $this->venda(['venda_status_nfe' => 'Issued', 'venda_id_nfe' => 'inv-i']);
        $semNota = $this->venda();

        Livewire::test(ListVendas::class)
            ->callTableBulkAction('atualizarStatusNfeEmLote', [$parada, $autorizada, $semNota]);

        $this->assertSame('Issued', $parada->fresh()->venda_status_nfe);
        Http::assertSentCount(1);
    }

    public function test_cancelamento_em_lote_cancela_so_as_elegiveis(): void
    {
        $elegivel = $this->venda([], $this->cartao);
        $nfeAutorizada = $this->venda(['venda_status_nfe' => 'Issued', 'venda_id_nfe' => 'c'], $this->pix);
        $fiado = $this->venda(['venda_valor_pago' => 10], $this->pix);
        $stone = $this->venda([], $this->cartao);
        $stone->pagamentos()->update(['pg_venda_tipo_integracao' => 'integrated']);

        Livewire::test(ListVendas::class)
            ->callTableBulkAction('cancelarVendasEmLote', [$elegivel, $nfeAutorizada, $fiado, $stone], ['motivo' => MotivoCancelamentoEnum::ERRO_LANCAMENTO->value]);

        $this->assertSame('CANCELADA', $elegivel->fresh()->venda_status);
        $this->assertSame(MotivoCancelamentoEnum::ERRO_LANCAMENTO, $elegivel->fresh()->venda_motivo_cancelamento);
        foreach ([$nfeAutorizada, $fiado, $stone] as $bloqueada) {
            $this->assertSame('FINALIZADA', $bloqueada->fresh()->venda_status);
        }
    }

    public function test_cancelamento_em_lote_exige_a_permissao_nova(): void
    {
        $gerente = User::factory()->create(['name_first' => 'Gil', 'name' => 'Gil']);
        $gerente->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:venda', 'guard_name' => 'web']));
        $this->actingAs($gerente);

        $this->assertFalse($gerente->can('cancelar_finalizada:venda'));
        $this->assertFalse(CancelarVendasEmLoteBulkAction::make()->isAuthorized());
    }

    public function test_exportacao_csv_traz_cabecalho_valores_e_situacao(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Maria', 'cliente_tipo' => 'PF', 'cliente_cpf' => '52998224725']);
        $venda = $this->venda(['venda_cliente_id' => $cliente->id], $this->pix, 40.5, statusEmissao: NfEmissao::STATUS_FALHA_ENVIO);

        $linhas = app(RelatorioVendasExportService::class)->linhas(Venda::query()->whereKey($venda->id));

        $this->assertSame('Venda', $linhas[0][0]);
        $this->assertCount(2, $linhas);
        $linha = $linhas[1];
        $this->assertSame($venda->id, $linha[0]);
        $this->assertSame('Maria', $linha[4]);
        $this->assertSame('52998224725', $linha[5]);
        $this->assertSame('40,50', $linha[9]);
        $this->assertSame('Pix', $linha[13]);
        $this->assertSame(NfEmissao::STATUS_FALHA_ENVIO, $linha[15]);
        $this->assertSame('Rejeição 999', $linha[20]);

        Livewire::test(ListVendas::class)
            ->callTableBulkAction('exportarVendasCsv', [$venda])
            ->assertFileDownloaded();

        Livewire::test(ListVendas::class)
            ->set('activeTab', 'falha')
            ->callAction('exportarCsv')
            ->assertFileDownloaded();
    }
}
