<?php

namespace Tests\Feature;

use App\Models\Caixa;
use App\Models\Maquininha;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Models\StoneWebhook;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoneWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_ID = 'or_bqopZVqtEtr123F45';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stone.webhook_user' => null,
            'services.stone.webhook_password' => null,
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
        ]);
        // PATCH .../closed nunca deve sair para a rede nos testes.
        Http::preventStrayRequests();
    }

    private function fakeStoneOk(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
    }

    private function payloadChargePaid(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'hook_j0R8BYh0dS123RQ34',
            'account' => ['id' => 'acc_Wd46j1nsecRFDE12G', 'name' => 'PARTNER TESTE'],
            'type' => 'charge.paid',
            'created_at' => '2023-10-10T20:28:13.6201993Z',
            'data' => [
                'id' => 'ch_NRPl6mouLuZ123FR4',
                'code' => '38332544765625',
                'amount' => 2000,
                'paid_amount' => 2000,
                'status' => 'paid',
                'payment_method' => 'credit_card',
                'order' => [
                    'id' => self::ORDER_ID,
                    'code' => 'D3MGIQI835',
                    'amount' => 2000,
                    'closed' => false,
                    'status' => 'pending',
                    'metadata' => [],
                ],
                'metadata' => [
                    'scheme_name' => 'MasterCard',
                    'authorization_code' => 'M21111',
                    'terminal_serial_number' => '6N021234',
                ],
            ],
        ], $overrides);
    }

    private function payloadChargeRefunded(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'hook_refund_1',
            'type' => 'charge.refunded',
            'created_at' => '2023-10-10T21:00:00Z',
            'data' => [
                'id' => 'ch_NRPl6mouLuZ123FR4',
                'code' => '38332544765625',
                'amount' => 2000,
                'canceled_amount' => 2000,
                'status' => 'canceled',
                'order' => [
                    'id' => self::ORDER_ID,
                    'code' => 'D3MGIQI835',
                    'metadata' => [],
                ],
                'metadata' => ['scheme_name' => 'MasterCard'],
            ],
        ], $overrides);
    }

    private function criarVenda(string $status = 'INICIADA'): Venda
    {
        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 100,
            'sessaocaixa_saldo_final' => 100,
            'sessaocaixa_user_id' => $user->id,
        ]);

        return Venda::create([
            'venda_status' => $status,
            'venda_sessao_caixa_id' => $sessao->id,
            'venda_valor_itens' => 20.00,
            'venda_valor_total' => 20.00,
            'venda_valor_pago' => $status === 'FINALIZADA' ? 20.00 : 0,
        ]);
    }

    private function criarPedido(Venda $venda, float $valor = 20.00, string $orderId = self::ORDER_ID, string $descNfe = 'creditCard'): StonePedido
    {
        $opcao = OpcoesPagamento::create([
            'opcaopag_nome' => 'Stone '.$descNfe,
            'opcaopag_desc_nfe' => $descNfe,
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => true,
        ]);
        $maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '6N021234']);

        return StonePedido::create([
            'stp_venda_id' => $venda->id,
            'stp_maquininha_id' => $maquininha->id,
            'stp_opcaopagamento_id' => $opcao->id,
            'stp_order_id' => $orderId,
            'stp_order_code' => 'D3MGIQI835',
            'stp_valor_solicitado' => $valor,
            'stp_status' => 'aguardando',
        ]);
    }

    // ── Fase 1 (auditoria) ─────────────────────────────────────────────────

    public function test_webhook_sem_credenciais_configuradas_persiste(): void
    {
        $response = $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid());

        $response->assertOk();
        $webhook = StoneWebhook::sole();
        $this->assertSame('charge.paid', $webhook->stw_evento);
        $this->assertSame('ch_NRPl6mouLuZ123FR4', $webhook->stw_charge_id);
        $this->assertNull($webhook->stw_autenticado);
    }

    public function test_webhook_com_credenciais_invalidas_retorna_401_mas_persiste(): void
    {
        config(['services.stone.webhook_user' => 'stone', 'services.stone.webhook_password' => 'segredo']);

        $response = $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid(), [
            'Authorization' => 'Basic '.base64_encode('stone:errado'),
        ]);

        $response->assertUnauthorized();
        $this->assertFalse(StoneWebhook::sole()->stw_autenticado);
    }

    public function test_webhook_com_credenciais_validas_persiste_autenticado(): void
    {
        config(['services.stone.webhook_user' => 'stone', 'services.stone.webhook_password' => 'segredo']);

        $response = $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid(), [
            'Authorization' => 'Basic '.base64_encode('stone:segredo'),
        ]);

        $response->assertOk();
        $this->assertTrue(StoneWebhook::sole()->stw_autenticado);
    }

    public function test_charge_paid_sem_stone_pedido_apenas_registra_o_evento(): void
    {
        $response = $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid());

        $response->assertOk();
        $this->assertSame(0, PagamentosVenda::count());
        $this->assertNull(StoneWebhook::sole()->stw_stone_pedido_id);
    }

    // ── Fase 2 (charge.paid) ───────────────────────────────────────────────

    public function test_charge_paid_com_stone_pedido_lanca_pagamento_e_fecha_pedido(): void
    {
        $this->fakeStoneOk();
        $venda = $this->criarVenda();
        $pedido = $this->criarPedido($venda, 20.00);
        // Insert cru: o mutator/enum de cartoes_pagamentos é legado e quebrado.
        DB::table('cartoes_pagamentos')->insert([
            'cartao_bandeira' => 'mastercard', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();

        $pagamento = PagamentosVenda::sole();
        $this->assertSame($venda->id, $pagamento->pg_venda_venda_id);
        $this->assertSame($pedido->stp_opcaopagamento_id, $pagamento->pg_venda_opcaopagamento_id);
        $this->assertSame('integrated', $pagamento->pg_venda_tipo_integracao);
        $this->assertSame('M21111', $pagamento->pg_venda_numero_autorizacao_cartao);
        $this->assertSame(20.00, (float) $pagamento->pg_venda_valor_pagamento);
        $this->assertNotNull($pagamento->pg_venda_cartao_id);

        $pedido->refresh();
        $this->assertSame('pago', $pedido->stp_status->value);
        $this->assertNotNull($pedido->stp_fechado_em);
        $this->assertSame(20.00, (float) $venda->fresh()->venda_valor_pago);

        $webhook = StoneWebhook::sole();
        $this->assertSame($pedido->id, $webhook->stw_stone_pedido_id);
        $this->assertSame($pagamento->id, $webhook->stw_pagamento_venda_id);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/orders/'.self::ORDER_ID.'/closed')
            && $request->data() === ['status' => 'paid']);
    }

    public function test_charge_paid_duplicado_nao_duplica_pagamento(): void
    {
        $this->fakeStoneOk();
        $venda = $this->criarVenda();
        $this->criarPedido($venda, 20.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();
        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid(['id' => 'hook_2']))->assertOk();

        $this->assertSame(1, PagamentosVenda::count());
        $this->assertSame(20.00, (float) $venda->fresh()->venda_valor_pago);
    }

    public function test_charge_paid_de_pix_lanca_pagamento_na_opcao_instantpayment(): void
    {
        $this->fakeStoneOk();
        $venda = $this->criarVenda();
        $pedido = $this->criarPedido($venda, 20.00, self::ORDER_ID, 'InstantPayment');

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid([
            'data' => [
                'payment_method' => 'pix',
                'metadata' => ['scheme_name' => null, 'authorization_code' => null],
            ],
        ]))->assertOk();

        $pagamento = PagamentosVenda::sole();
        $this->assertSame($pedido->stp_opcaopagamento_id, $pagamento->pg_venda_opcaopagamento_id);
        $this->assertSame('integrated', $pagamento->pg_venda_tipo_integracao);
        $this->assertNull($pagamento->pg_venda_cartao_id);
        $this->assertSame(20.00, (float) $venda->fresh()->venda_valor_pago);
        $this->assertSame('pago', $pedido->fresh()->stp_status->value);
    }

    public function test_charge_paid_parcial_mantem_pedido_aberto(): void
    {
        $this->fakeStoneOk();
        $venda = $this->criarVenda();
        $pedido = $this->criarPedido($venda, 20.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid([
            'data' => ['amount' => 1200, 'paid_amount' => 1200, 'order' => ['amount' => 1200]],
        ]))->assertOk();

        $pedido->refresh();
        $this->assertSame('pago_parcial', $pedido->stp_status->value);
        $this->assertNull($pedido->stp_fechado_em);
        $this->assertSame(12.00, (float) $venda->fresh()->venda_valor_pago);
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    public function test_charge_paid_com_falha_no_fechamento_ainda_lanca_pagamento(): void
    {
        Http::fake(['api.pagar.me/*/closed' => Http::response(['message' => 'erro'], 500)]);

        $venda = $this->criarVenda();
        $pedido = $this->criarPedido($venda, 20.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();

        $this->assertSame(1, PagamentosVenda::count());
        $pedido->refresh();
        $this->assertSame('pago', $pedido->stp_status->value);
        $this->assertNull($pedido->stp_fechado_em);
    }

    public function test_correlacao_ignora_metadata_do_order(): void
    {
        $this->fakeStoneOk();
        $vendaCerta = $this->criarVenda();
        $this->criarPedido($vendaCerta, 20.00);
        $vendaErrada = $this->criarVenda();

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid([
            'data' => ['order' => ['metadata' => ['venda_id' => $vendaErrada->id]]],
        ]))->assertOk();

        $pagamento = PagamentosVenda::sole();
        $this->assertSame($vendaCerta->id, $pagamento->pg_venda_venda_id);
    }

    // ── Fase 2 (charge.refunded) ──────────────────────────────────────────

    public function test_charge_refunded_antes_de_finalizar_remove_o_pagamento(): void
    {
        $this->fakeStoneOk();
        $venda = $this->criarVenda();
        $pedido = $this->criarPedido($venda, 20.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();
        $this->assertSame(1, PagamentosVenda::count());

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargeRefunded())->assertOk();

        $this->assertSame(0, PagamentosVenda::count());
        $this->assertSame(0.0, (float) $venda->fresh()->venda_valor_pago);
        $this->assertSame('estornado', $pedido->fresh()->stp_status->value);
    }

    public function test_charge_refunded_apos_finalizar_reverte_e_lanca_saida_no_caixa(): void
    {
        $this->fakeStoneOk();
        $venda = $this->criarVenda();
        $pedido = $this->criarPedido($venda, 20.00);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid())->assertOk();

        $venda->update(['venda_status' => 'FINALIZADA']);

        $this->postJson('/api/webhook/stone-connect', $this->payloadChargeRefunded())->assertOk();

        $this->assertSame(0, PagamentosVenda::count());
        $this->assertSame('estornado', $pedido->fresh()->stp_status->value);

        $saida = MovimentacoesSessaoCaixa::where('mov_tipo', 'SAIDA')->sole();
        $this->assertSame($venda->id, $saida->mov_venda_id);
        $this->assertSame(20.00, (float) $saida->mov_valor);
    }
}
