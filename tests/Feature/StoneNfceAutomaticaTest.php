<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoOrigem;
use App\Jobs\EmitirNfeAutomaticaJob;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use App\Services\Nfe\EmissaoNfeAutomaticaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Revisão 1 (D6): a venda que o webhook Stone finaliza emite a NFC-e logo
 * depois da resposta (sem worker), explica no log quando não emite e tem rede
 * de segurança no stone:conciliar-pedidos.
 */
class StoneNfceAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private const LOG = 'logs/stone-nfce-automatica-test.log';

    private const URL_NFE = 'api.nfse.io/v2/companies/company-teste/consumerinvoices';

    private Produto $produto;

    /** Respostas da NFe.io para o próximo POST, na ordem; vazio devolve sucesso. */
    private array $filaNfe = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stone.webhook_user' => null,
            'services.stone.webhook_password' => null,
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
            'logging.channels.stone' => ['driver' => 'single', 'path' => storage_path(self::LOG), 'level' => 'debug'],
        ]);
        @unlink(storage_path(self::LOG));

        Http::preventStrayRequests();
        Http::fake([
            'api.pagar.me/*' => Http::response([], 200),
            self::URL_NFE => fn () => array_shift($this->filaNfe) ?? Http::response(['id' => 'inv-1', 'status' => 'Processing']),
        ]);

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);

        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        @unlink(storage_path(self::LOG));

        parent::tearDown();
    }

    private function forma(bool $envioAutomatico): OpcoesPagamento
    {
        return OpcoesPagamento::create([
            'opcaopag_nome' => 'Crédito',
            'opcaopag_desc_nfe' => 'creditCard',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => true,
            'opcaopag_envio_automatico_nfe' => $envioAutomatico,
        ]);
    }

    private function sessaoCaixaAberta(): SessaoCaixa
    {
        $user = User::factory()->admin()->create(['name_first' => 'Admin']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    private function maquininha(): Maquininha
    {
        return Maquininha::create(['nome' => 'Entregador', 'operadora' => 'stone', 'maquininha_connect_ativo' => true, 'numero_serie' => '6N021234']);
    }

    /** Pedido de entrega de R$ 50 já cobrado em Listado, pronto para receber o charge.paid. */
    private function pedidoCobradoEmListado(): Pedido
    {
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 50.00,
            'item_pedido_valor' => 50.00,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        StonePedido::create([
            'stp_pedido_id' => $pedido->id,
            'stp_origem' => StonePedidoOrigem::Pedido,
            'stp_maquininha_id' => $this->maquininha()->id,
            'stp_order_id' => 'or_nfce_1',
            'stp_order_code' => 'NFCE1',
            'stp_valor_solicitado' => 50.00,
            'stp_status' => 'aguardando',
            'stp_modo' => StonePedidoModo::Listado->value,
        ]);

        return $pedido;
    }

    private function chargePaid(): void
    {
        $this->postJson('/api/webhook/stone-connect', [
            'id' => 'hook_nfce_1',
            'type' => 'charge.paid',
            'created_at' => '2026-10-28T10:00:00Z',
            'data' => [
                'id' => 'ch_nfce_1',
                'code' => '1',
                'amount' => 5000,
                'paid_amount' => 5000,
                'status' => 'paid',
                'payment_method' => 'credit_card',
                'order' => ['id' => 'or_nfce_1', 'code' => 'NFCE1', 'amount' => 5000, 'closed' => false, 'status' => 'pending', 'metadata' => []],
                'metadata' => ['scheme_name' => 'MasterCard', 'authorization_code' => 'A1'],
            ],
        ])->assertOk();
    }

    private function postsNfe(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'consumerinvoices'))->count();
    }

    private function log(): string
    {
        return is_file(storage_path(self::LOG)) ? (string) file_get_contents(storage_path(self::LOG)) : '';
    }

    public function test_r6_charge_paid_finaliza_a_venda_e_despacha_o_job_depois_da_resposta(): void
    {
        Bus::fake();
        $this->sessaoCaixaAberta();
        $this->forma(envioAutomatico: true);
        $this->pedidoCobradoEmListado();

        $this->chargePaid();

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        Bus::assertDispatchedAfterResponse(EmitirNfeAutomaticaJob::class, fn (EmitirNfeAutomaticaJob $job) => $job->vendaId === $venda->id);
    }

    public function test_r6_sem_bus_fake_a_nfce_sai_no_mesmo_processo_sem_worker(): void
    {
        $this->sessaoCaixaAberta();
        $this->forma(envioAutomatico: true);
        $this->pedidoCobradoEmListado();

        $this->chargePaid();

        $this->assertSame(1, $this->postsNfe());
        $this->assertSame('inv-1', Venda::sole()->venda_id_nfe);
    }

    public function test_r7_o_job_emite_uma_vez_e_nao_repete_com_venda_id_nfe_preenchido(): void
    {
        Bus::fake();
        $this->sessaoCaixaAberta();
        $this->forma(envioAutomatico: true);
        $this->pedidoCobradoEmListado();
        $this->chargePaid();
        $venda = Venda::sole();
        $this->assertSame(0, $this->postsNfe());

        (new EmitirNfeAutomaticaJob($venda->id))->handle(app(EmissaoNfeAutomaticaService::class));

        $this->assertSame(1, $this->postsNfe());
        $this->assertSame('inv-1', $venda->fresh()->venda_id_nfe);

        (new EmitirNfeAutomaticaJob($venda->id))->handle(app(EmissaoNfeAutomaticaService::class));

        $this->assertSame(1, $this->postsNfe());
    }

    public function test_r7_o_job_ignora_venda_que_nao_esta_finalizada(): void
    {
        $venda = Venda::create(['venda_status' => 'INICIADA']);

        (new EmitirNfeAutomaticaJob($venda->id))->handle(app(EmissaoNfeAutomaticaService::class));

        $this->assertSame(0, $this->postsNfe());
    }

    public function test_r8_forma_sem_envio_automatico_nao_posta_na_nfeio_e_explica_no_log(): void
    {
        $this->sessaoCaixaAberta();
        $this->forma(envioAutomatico: false);
        $this->pedidoCobradoEmListado();

        $this->chargePaid();

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertSame(0, $this->postsNfe());
        $this->assertNull($venda->venda_id_nfe);
        $this->assertStringContainsString('NFC-e automática não emitida', $this->log());
        $this->assertStringContainsString('nenhum pagamento da venda usa forma com envio automático de NFC-e', $this->log());
        $this->assertStringContainsString('WARNING', $this->log());
    }

    public function test_r8_motivo_nao_emitir_cobre_cada_condicao_de_deve_emitir(): void
    {
        $servico = app(EmissaoNfeAutomaticaService::class);

        $semForma = Venda::create(['venda_status' => 'FINALIZADA']);
        $this->assertSame('nenhum pagamento da venda usa forma com envio automático de NFC-e', $servico->motivoNaoEmitir($semForma));
        $this->assertFalse($servico->deveEmitir($semForma));

        $soCortesia = $this->vendaFinalizadaPelaStone(minutosAtras: 10, descartar: true);
        ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $soCortesia->id,
            'item_venda_produto_id' => $this->produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 50,
            'item_venda_desconto' => 0,
            'item_venda_valor' => 0,
            'item_venda_valor_base_calculo' => 0,
            'item_venda_status' => 'INSERIDO',
            'item_venda_cortesia' => true,
        ]);
        $this->assertSame('a venda não tem item fiscal (somente cortesia)', $servico->motivoNaoEmitir($soCortesia));

        $comItem = $this->vendaFinalizadaPelaStone(minutosAtras: 10, descartar: true);
        $this->assertNull($servico->motivoNaoEmitir($comItem));
        $this->assertTrue($servico->deveEmitir($comItem));

        Empresa::query()->update(['empresa_api_nfeio_apikey' => null]);
        $this->assertSame('NFe.io não configurada na empresa', $servico->motivoNaoEmitir($comItem));
    }

    // ── Rede de segurança no stone:conciliar-pedidos (R9) ────────────────────

    private function vendaFinalizadaPelaStone(int $minutosAtras, bool $envioAutomatico = true, ?string $idNfe = null, StonePedidoOrigem $origem = StonePedidoOrigem::Pedido, bool $descartar = false): Venda
    {
        $venda = Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => 50,
            'venda_valor_pago' => 50,
            'venda_id_nfe' => $idNfe,
            'venda_datahora_iniciada' => now()->subMinutes($minutosAtras + 1),
            'venda_datahora_finalizada' => now()->subMinutes($minutosAtras),
        ]);

        $forma = OpcoesPagamento::firstOrCreate(
            ['opcaopag_nome' => $envioAutomatico ? 'Crédito auto' : 'Crédito manual'],
            [
                'opcaopag_desc_nfe' => 'creditCard', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0,
                'opcaopag_stone_integrada' => true, 'opcaopag_envio_automatico_nfe' => $envioAutomatico,
            ],
        );

        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $forma->id,
            'pg_venda_tipo_integracao' => 'integrated',
            'pg_venda_valor_pagamento' => 50,
            'pg_venda_valor_pago_pelo_cliente' => 50,
        ]);

        if (! $descartar) {
            StonePedido::create([
                'stp_venda_id' => $venda->id,
                'stp_origem' => $origem,
                'stp_pedido_id' => $origem === StonePedidoOrigem::Pedido ? Pedido::create(['pedido_status' => 'FINALIZADO'])->id : null,
                'stp_maquininha_id' => $this->maquininha()->id,
                'stp_order_id' => 'or_rede_'.$venda->id,
                'stp_valor_solicitado' => 50,
                'stp_valor_pago' => 50,
                'stp_status' => 'pago',
                'stp_modo' => StonePedidoModo::Listado->value,
                'stp_fechado_em' => now(),
            ]);
        }

        return $venda;
    }

    private function conciliar(): void
    {
        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();
    }

    public function test_r9_emite_venda_finalizada_ha_10_minutos_sem_nota_e_so_uma_vez(): void
    {
        $venda = $this->vendaFinalizadaPelaStone(minutosAtras: 10);

        $this->conciliar();

        $this->assertSame(1, $this->postsNfe());
        $this->assertSame('inv-1', $venda->fresh()->venda_id_nfe);

        $this->conciliar();

        $this->assertSame(1, $this->postsNfe());
    }

    public function test_r9_emite_tambem_a_venda_de_sessao_de_mesa(): void
    {
        $this->vendaFinalizadaPelaStone(minutosAtras: 10, origem: StonePedidoOrigem::SessaoMesa);

        $this->conciliar();

        $this->assertSame(1, $this->postsNfe());
    }

    public function test_r9_ignora_venda_finalizada_ha_2_minutos(): void
    {
        $this->vendaFinalizadaPelaStone(minutosAtras: 2);

        $this->conciliar();

        $this->assertSame(0, $this->postsNfe());
    }

    public function test_r9_ignora_venda_finalizada_ha_25_horas(): void
    {
        $this->vendaFinalizadaPelaStone(minutosAtras: 25 * 60);

        $this->conciliar();

        $this->assertSame(0, $this->postsNfe());
    }

    public function test_r9_ignora_venda_que_ja_tem_nota(): void
    {
        $this->vendaFinalizadaPelaStone(minutosAtras: 10, idNfe: 'inv-existente');

        $this->conciliar();

        $this->assertSame(0, $this->postsNfe());
    }

    public function test_r9_ignora_venda_cuja_forma_nao_tem_envio_automatico(): void
    {
        $this->vendaFinalizadaPelaStone(minutosAtras: 10, envioAutomatico: false);

        $this->conciliar();

        $this->assertSame(0, $this->postsNfe());
    }

    public function test_r9_ignora_venda_do_pdv_sem_stone_pedido_de_pedido_ou_mesa(): void
    {
        $this->vendaFinalizadaPelaStone(minutosAtras: 10, origem: StonePedidoOrigem::Venda);

        $this->conciliar();

        $this->assertSame(0, $this->postsNfe());
    }

    public function test_r9_o_horario_fixo_define_a_janela_de_5_minutos_a_24_horas(): void
    {
        Carbon::setTestNow('2026-10-28 12:00:00');
        $dentro = $this->vendaFinalizadaPelaStone(minutosAtras: 6);
        $naBorda = $this->vendaFinalizadaPelaStone(minutosAtras: 4);

        $this->conciliar();

        $this->assertSame('inv-1', $dentro->fresh()->venda_id_nfe);
        $this->assertNull($naBorda->fresh()->venda_id_nfe);

        $this->travel(2)->minutes();
        $this->conciliar();

        $this->assertSame('inv-1', $naBorda->fresh()->venda_id_nfe);
        $this->assertSame(2, $this->postsNfe());
    }

    public function test_r9_falha_da_nfeio_e_logada_e_nao_interrompe_o_comando(): void
    {
        $this->filaNfe = [Http::response(['message' => 'indisponível'], 500), Http::response(['id' => 'inv-2', 'status' => 'Processing'])];
        $primeira = $this->vendaFinalizadaPelaStone(minutosAtras: 20);
        $segunda = $this->vendaFinalizadaPelaStone(minutosAtras: 10);

        $this->conciliar();

        $this->assertNull($primeira->fresh()->venda_id_nfe);
        $this->assertSame('inv-2', $segunda->fresh()->venda_id_nfe);
        $this->assertStringContainsString('NFC-e automática da venda finalizada pela Stone falhou', $this->log());
    }

    public function test_r9_nao_emite_em_dobro_quando_outro_processo_ja_esta_emitindo(): void
    {
        $venda = $this->vendaFinalizadaPelaStone(minutosAtras: 10);

        $outroProcesso = cache()->lock("nfe-automatica:venda:{$venda->id}", 120);
        $this->assertTrue($outroProcesso->get());

        $this->conciliar();

        $this->assertSame(0, $this->postsNfe());

        $outroProcesso->release();
        $this->conciliar();

        $this->assertSame(1, $this->postsNfe());
    }
}
