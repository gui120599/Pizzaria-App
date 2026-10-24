<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoCorrecaoPagamentoEnum;
use App\Enums\TipoLancamento;
use App\Exceptions\CorrecaoPagamentoException;
use App\Exceptions\NfeIoException;
use App\Filament\Resources\Vendas\Pages\ListVendas;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\CorrecaoPagamento;
use App\Models\Empresa;
use App\Models\ItensVenda;
use App\Models\Lancamento;
use App\Models\NfEmissao;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\AlteracaoVendaService;
use App\Services\Nfe\ReenvioNfeService;
use App\Services\NfeIoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Envio/reenvio manual da NFC-e, comprador omitido com cadastro incompleto e
 * "Alterar venda" (cliente) na tabela de vendas.
 */
class NfeReenvioEAlteracaoVendaTest extends TestCase
{
    use RefreshDatabase;

    private const URL_EMISSAO = 'api.nfse.io/v2/companies/company-teste/consumerinvoices';

    private Cliente $semCpf;

    private Cliente $completo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Ana', 'name' => 'Ana']));

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);

        $this->semCpf = Cliente::create(['cliente_nome' => 'Sem CPF', 'cliente_tipo' => 'PF']);
        $this->completo = Cliente::create(['cliente_nome' => 'Completo', 'cliente_tipo' => 'PF', 'cliente_cpf' => '52998224725']);
    }

    private function venda(?Cliente $cliente = null, ?string $statusNfe = null): Venda
    {
        $categoria = Categoria::firstOrCreate(['categoria_nome' => 'Geral']);
        $produto = Produto::firstOrCreate(['produto_descricao' => 'Calabresa'], ['produto_categoria_id' => $categoria->id, 'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value, 'produto_preco_venda' => 40, 'produto_codigo_NCM' => '19059090', 'produto_CFOP' => '5102', 'produto_CSOSN' => '102', 'produto_unidade_comercial' => 'UN']);

        $venda = Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_cliente_id' => $cliente?->id,
            'venda_status_nfe' => $statusNfe,
            'venda_valor_total' => 40,
            'venda_valor_pago' => 40,
            'venda_datahora_iniciada' => now(),
            'venda_datahora_finalizada' => now(),
        ]);
        ItensVenda::create(['item_numero' => 1, 'item_venda_venda_id' => $venda->id, 'item_venda_produto_id' => $produto->id, 'item_venda_quantidade' => 1, 'item_venda_quantidade_tributavel' => 1, 'item_venda_valor_unitario' => 40, 'item_venda_valor' => 40, 'item_venda_status' => 'INSERIDO']);

        return $venda;
    }

    public function test_cliente_sem_cpf_emite_sem_comprador_e_registra_o_motivo(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL_EMISSAO => Http::response(['id' => 'inv-1', 'status' => 'Processing'])]);
        $venda = $this->venda($this->semCpf);

        app(NfeIoService::class)->emitir($venda);

        Http::assertSent(fn ($request) => $request['buyer'] === null);
        $this->assertSame('CPF não informado', $venda->nfEmissaoAtual->comprador_motivo_omissao);
    }

    public function test_cliente_completo_vai_identificado(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL_EMISSAO => Http::response(['id' => 'inv-1', 'status' => 'Processing'])]);
        $venda = $this->venda($this->completo);

        app(NfeIoService::class)->emitir($venda);

        Http::assertSent(fn ($request) => $request['buyer']['federalTaxNumber'] === '52998224725');
        $this->assertNull($venda->nfEmissaoAtual->comprador_motivo_omissao);
    }

    public function test_falha_grava_o_erro_e_o_reenvio_reaproveita_o_numero(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL_EMISSAO => Http::sequence()
            ->push(['message' => 'Rejeição 999: teste'], 400)
            ->push(['id' => 'inv-2', 'status' => 'Processing'])]);
        Http::fake(['api.nfse.io/v2/companies/company-teste/consumerinvoices/inv-2' => Http::response(['status' => 'Processing'])]);
        $venda = $this->venda($this->completo);

        try {
            app(NfeIoService::class)->emitir($venda);
            $this->fail('Era para falhar o envio.');
        } catch (NfeIoException) {
        }

        $emissao = $venda->fresh()->nfEmissaoAtual;
        $this->assertSame(NfEmissao::STATUS_FALHA_ENVIO, $emissao->status);
        $this->assertStringContainsString('Rejeição 999', $emissao->erro_envio);
        $this->assertNull($venda->fresh()->venda_status_nfe);

        $this->assertNull(app(ReenvioNfeService::class)->motivoBloqueio($venda->fresh()));
        $status = app(ReenvioNfeService::class)->reenviar($venda->fresh());

        $this->assertSame('Processing', $status);
        $this->assertSame(1, NfEmissao::count());
        $this->assertSame($emissao->numero, $emissao->fresh()->numero);
        $this->assertNull($emissao->fresh()->erro_envio);
    }

    public function test_reenvio_bloqueado_com_nota_autorizada_em_andamento_ou_sem_item_fiscal(): void
    {
        $reenvio = app(ReenvioNfeService::class);

        $this->assertStringContainsString('já está autorizada', $reenvio->motivoBloqueio($this->venda($this->completo, 'Issued')));
        $this->assertStringContainsString('em andamento', $reenvio->motivoBloqueio($this->venda($this->completo, 'Processing')));
        $this->assertNull($reenvio->motivoBloqueio($this->venda($this->completo, 'Cancelled')));
        $this->assertNull($reenvio->motivoBloqueio($this->venda($this->completo, 'Error')));
        $this->assertNull($reenvio->motivoBloqueio($this->venda($this->completo)));

        $this->expectException(NfeIoException::class);
        $reenvio->reenviar($this->venda($this->completo, 'Issued'));
    }

    public function test_pode_atualizar_status_so_com_nota_na_nfeio_e_nao_final(): void
    {
        $reenvio = app(ReenvioNfeService::class);
        $parada = $this->venda($this->completo);
        $parada->update(['venda_id_nfe' => 'abc']);
        $autorizada = $this->venda($this->completo, 'Issued');
        $autorizada->update(['venda_id_nfe' => 'def']);

        $this->assertTrue($reenvio->podeAtualizarStatus($parada));
        $this->assertFalse($reenvio->podeAtualizarStatus($autorizada));
        $this->assertFalse($reenvio->podeAtualizarStatus($this->venda($this->completo)));
    }

    public function test_trocar_e_remover_cliente_registra_a_auditoria(): void
    {
        $venda = $this->venda($this->semCpf);
        $service = app(AlteracaoVendaService::class);

        $service->trocarCliente($venda, $this->completo->id, 'cadastro errado');
        $this->assertSame($this->completo->id, $venda->fresh()->venda_cliente_id);

        $service->trocarCliente($venda->fresh(), null);
        $this->assertNull($venda->fresh()->venda_cliente_id);

        $correcoes = CorrecaoPagamento::where('venda_id', $venda->id)->orderBy('id')->get();
        $this->assertCount(2, $correcoes);
        $this->assertSame(TipoCorrecaoPagamentoEnum::ClienteVenda, $correcoes[0]->tipo);
        $this->assertSame('Sem CPF', $correcoes[0]->antes['cliente']);
        $this->assertSame('Completo', $correcoes[0]->depois['cliente']);
        $this->assertSame('cadastro errado', $correcoes[0]->justificativa);
    }

    public function test_troca_de_cliente_bloqueada_com_nota_autorizada_ou_fiado(): void
    {
        $service = app(AlteracaoVendaService::class);

        $autorizada = $this->venda($this->semCpf, 'Issued');
        $this->assertStringContainsString('autorizada', $service->motivoBloqueioCliente($autorizada));

        $fiado = $this->venda($this->semCpf);
        Lancamento::create(['tipo' => TipoLancamento::Receber, 'venda_id' => $fiado->id, 'cliente_id' => $this->semCpf->id, 'descricao' => 'fiado', 'valor' => 10, 'vencimento' => now()->addWeek()->toDateString(), 'status' => StatusLancamento::Pendente]);
        $this->assertStringContainsString('fiado', $service->motivoBloqueioCliente($fiado));

        $this->expectException(CorrecaoPagamentoException::class);
        $service->trocarCliente($fiado, $this->completo->id);
    }

    public function test_acoes_da_tabela_enviar_e_alterar_cliente(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::URL_EMISSAO => Http::response(['id' => 'inv-9', 'status' => 'Processing']),
            self::URL_EMISSAO.'/inv-9' => Http::response(['status' => 'Processing']),
        ]);
        $venda = $this->venda($this->semCpf);
        $autorizada = $this->venda($this->completo, 'Issued');

        Livewire::test(ListVendas::class)
            ->assertTableActionVisible('enviarNfe', $venda)
            ->assertTableActionHidden('enviarNfe', $autorizada)
            ->assertTableActionDisabled('alterarVenda', $autorizada)
            ->callTableAction('alterarVenda', $venda, ['o_que' => 'cliente', 'cliente_id' => $this->completo->id, 'justificativa_cliente' => 'ajuste'])
            ->assertNotified('Venda #'.$venda->id.' alterada')
            ->callTableAction('enviarNfe', $venda)
            ->assertNotified('NFC-e enviada');

        $this->assertSame($this->completo->id, $venda->fresh()->venda_cliente_id);
        $this->assertSame('inv-9', $venda->fresh()->venda_id_nfe);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['buyer']['federalTaxNumber'] === '52998224725');
    }
}
