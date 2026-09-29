<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoOrigem;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Maquininha;
use App\Models\Mesa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobre o cenário novo: StonePedido de origem Pedido/SessaoMesa (sem Venda
 * prévia) — o webhook charge.paid cria a Venda (StoneVendaAutomaticaService)
 * e finaliza quando o pagamento cobre o total. Espelha o setup de
 * StoneWebhookControllerTest, que cobre o fluxo original do PDV (origem Venda).
 */
class StoneVendaPeloWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_ID = 'or_venda_webhook_1';

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stone.webhook_user' => null,
            'services.stone.webhook_password' => null,
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
            'services.stone.pedido_direto' => true,
            'logging.channels.stone' => ['driver' => 'single', 'path' => storage_path('logs/stone-venda-webhook-test.log'), 'level' => 'debug'],
        ]);
        @unlink(storage_path('logs/stone-venda-webhook-test.log'));
        Http::preventStrayRequests();
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
            'produto_valor_percentual_icms' => 10,
            'produto_valor_percentual_pis' => 1,
            'produto_valor_percentual_cofins' => 2,
        ]);
    }

    private function abrirSessaoCaixa(): SessaoCaixa
    {
        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    private function itemPedido(Pedido $pedido, float $quantidade = 1): ItensPedido
    {
        $valor = $this->produto->produto_preco_venda * $quantidade;

        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => $quantidade,
            'item_pedido_valor_unitario' => $this->produto->produto_preco_venda,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    private function maquininha(): Maquininha
    {
        return Maquininha::create(['nome' => 'Entregador', 'operadora' => 'stone', 'numero_serie' => '6N021234']);
    }

    private function stonePedidoDePedido(Pedido $pedido, float $valor): StonePedido
    {
        return StonePedido::create([
            'stp_pedido_id' => $pedido->id,
            'stp_origem' => StonePedidoOrigem::Pedido,
            'stp_maquininha_id' => $this->maquininha()->id,
            'stp_order_id' => self::ORDER_ID,
            'stp_order_code' => 'D3MGIQI835',
            'stp_valor_solicitado' => $valor,
            'stp_status' => 'aguardando',
            'stp_modo' => StonePedidoModo::Listado->value,
        ]);
    }

    private function stonePedidoDeSessaoMesa(SessaoMesa $sessaoMesa, float $valor): StonePedido
    {
        return StonePedido::create([
            'stp_sessao_mesa_id' => $sessaoMesa->id,
            'stp_origem' => StonePedidoOrigem::SessaoMesa,
            'stp_maquininha_id' => $this->maquininha()->id,
            'stp_order_id' => self::ORDER_ID,
            'stp_order_code' => 'D3MGIQI835',
            'stp_valor_solicitado' => $valor,
            'stp_status' => 'aguardando',
            'stp_modo' => StonePedidoModo::Listado->value,
        ]);
    }

    private function payloadChargePaid(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'hook_venda_webhook_1',
            'type' => 'charge.paid',
            'created_at' => '2026-09-29T10:00:00Z',
            'data' => [
                'id' => 'ch_venda_webhook_1',
                'code' => '38332544765625',
                'amount' => 5000,
                'paid_amount' => 5000,
                'status' => 'paid',
                'payment_method' => 'credit_card',
                'order' => [
                    'id' => self::ORDER_ID,
                    'code' => 'D3MGIQI835',
                    'amount' => 5000,
                    'closed' => false,
                    'status' => 'pending',
                    'metadata' => [],
                ],
                'metadata' => [
                    'scheme_name' => 'MasterCard',
                    'authorization_code' => 'M21111',
                ],
            ],
        ], $overrides);
    }

    public function test_pagamento_integral_de_pedido_avulso_finaliza_a_venda(): void
    {
        $this->abrirSessaoCaixa();
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $this->itemPedido($pedido, 1);
        $this->stonePedidoDePedido($pedido, 50.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertSame(50.00, (float) $venda->venda_valor_pago);

        $pedido->refresh();
        $this->assertSame('FINALIZADO', $pedido->pedido_status);
        $this->assertSame($venda->id, $pedido->pedido_venda_id);

        $movimento = MovimentacoesSessaoCaixa::where('mov_tipo', 'ENTRADA')->sole();
        $this->assertSame($venda->id, $movimento->mov_venda_id);

        $stonePedido = StonePedido::sole();
        $this->assertSame($venda->id, $stonePedido->stp_venda_id);
        $this->assertSame('pago', $stonePedido->stp_status->value);
    }

    public function test_pagamento_integral_de_sessao_de_mesa_finaliza_sessao_e_libera_mesa(): void
    {
        $sessaoCaixa = $this->abrirSessaoCaixa();
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 4', 'mesa_status' => 'OCUPADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => $sessaoCaixa->sessaocaixa_user_id,
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $mesa->update(['mesa_sessao_atual_id' => $sessaoMesa->id]);

        $pedido = Pedido::create(['pedido_sessao_mesa_id' => $sessaoMesa->id, 'pedido_status' => 'ENTREGUE']);
        $this->itemPedido($pedido, 1);

        $this->stonePedidoDeSessaoMesa($sessaoMesa, 50.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);

        $sessaoMesa->refresh();
        $this->assertSame('FINALIZADA', $sessaoMesa->sessao_mesa_status);

        $mesa->refresh();
        $this->assertSame('LIBERADA', $mesa->mesa_status);
        $this->assertNull($mesa->mesa_sessao_atual_id);

        $this->assertSame('FINALIZADO', $pedido->fresh()->pedido_status);
    }

    public function test_pagamento_parcial_mantem_a_venda_aberta_sem_baixar_pedido(): void
    {
        $this->abrirSessaoCaixa();
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $this->itemPedido($pedido, 1); // total 50.00

        $this->stonePedidoDePedido($pedido, 50.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid([
            'data' => ['amount' => 3000, 'paid_amount' => 3000, 'order' => ['amount' => 3000]],
        ]))->assertOk();

        $venda = Venda::sole();
        $this->assertSame('INICIADA', $venda->venda_status);
        $this->assertSame(30.00, (float) $venda->venda_valor_pago);

        $pedido->refresh();
        $this->assertNotSame('FINALIZADO', $pedido->pedido_status);
        $this->assertNull($pedido->pedido_venda_id);
    }

    public function test_duas_entregas_do_mesmo_charge_nao_criam_duas_vendas(): void
    {
        $this->abrirSessaoCaixa();
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $this->itemPedido($pedido, 1);
        $this->stonePedidoDePedido($pedido, 50.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();
        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid(['id' => 'hook_2']))->assertOk();

        $this->assertSame(1, Venda::count());
        $this->assertSame(1, PagamentosVenda::count());
    }

    public function test_sem_sessao_de_caixa_aberta_finaliza_a_venda_sem_sessao(): void
    {
        // O estado do caixa nunca bloqueia o recebimento: a venda nasce e é
        // finalizada mesmo sem nenhuma sessão ABERTA no momento — fica órfã,
        // pra ser vinculada depois (ver SessaoCaixaService::vincularVendasOrfas).
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $this->itemPedido($pedido, 1);
        $this->stonePedidoDePedido($pedido, 50.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertNull($venda->venda_sessao_caixa_id);

        $stonePedido = StonePedido::sole();
        $this->assertSame($venda->id, $stonePedido->stp_venda_id);
        $this->assertNull($stonePedido->stp_erro);

        $this->assertSame('FINALIZADO', $pedido->fresh()->pedido_status);
    }

    public function test_duas_sessoes_de_caixa_abertas_finaliza_a_venda_sem_sessao(): void
    {
        $this->abrirSessaoCaixa();
        $this->abrirSessaoCaixa();

        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $this->itemPedido($pedido, 1);
        $this->stonePedidoDePedido($pedido, 50.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertNull($venda->venda_sessao_caixa_id);
        $this->assertNull(StonePedido::sole()->stp_erro);
    }

    public function test_itens_ja_lancados_no_pdv_anexam_na_venda_existente(): void
    {
        $sessaoCaixa = $this->abrirSessaoCaixa();
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $item = $this->itemPedido($pedido, 1);

        // O caixa lançou manualmente no PDV enquanto a maquininha processava.
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
        ]);
        ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $venda->id,
            'item_venda_produto_id' => $this->produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 50.00,
            'item_venda_valor' => 50.00,
            'item_venda_status' => 'INSERIDO',
        ]);
        $item->update(['item_pedido_venda_id' => $venda->id]);

        $this->stonePedidoDePedido($pedido, 50.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();

        $this->assertSame(1, Venda::count());
        $this->assertSame($venda->id, StonePedido::sole()->stp_venda_id);
    }

    public function test_charge_refunded_de_venda_criada_pelo_webhook_reverte_pagamento_e_caixa(): void
    {
        $this->abrirSessaoCaixa();
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        $this->itemPedido($pedido, 1);
        $this->stonePedidoDePedido($pedido, 50.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();
        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);

        $this->postJson('/api/webhook/stone-connect', [
            'id' => 'hook_refund_1',
            'type' => 'charge.refunded',
            'data' => [
                'id' => 'ch_venda_webhook_1',
                'code' => '38332544765625',
                'amount' => 5000,
                'canceled_amount' => 5000,
                'status' => 'canceled',
                'order' => ['id' => self::ORDER_ID, 'code' => 'D3MGIQI835', 'metadata' => []],
                'metadata' => ['scheme_name' => 'MasterCard'],
            ],
        ])->assertOk();

        $this->assertSame(0, PagamentosVenda::count());
        $saida = MovimentacoesSessaoCaixa::where('mov_tipo', 'SAIDA')->sole();
        $this->assertSame($venda->id, $saida->mov_venda_id);
        $this->assertSame(50.00, (float) $saida->mov_valor);
    }
}
