<?php

namespace Tests\Feature;

use App\Models\StoneWebhook;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoneWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

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
                'amount' => 12500,
                'status' => 'paid',
                'payment_method' => 'credit_card',
                'order' => [
                    'id' => 'or_bqopZVqtEtr123F45',
                    'code' => 'D3MGIQI835',
                    'amount' => 12500,
                    'closed' => false,
                    'status' => 'pending',
                    'metadata' => [],
                ],
                'metadata' => [
                    'authorization_code' => 'M21111',
                    'terminal_serial_number' => '6N021234',
                ],
            ],
        ], $overrides);
    }

    public function test_webhook_sem_credenciais_configuradas_persiste_e_vincula_a_venda(): void
    {
        config(['services.stone.webhook_user' => null, 'services.stone.webhook_password' => null]);
        $venda = Venda::create(['venda_status' => 'FINALIZADA']);

        $response = $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid([
            'data' => ['order' => ['metadata' => ['venda_id' => $venda->id]]],
        ]));

        $response->assertOk();

        $webhook = StoneWebhook::sole();
        $this->assertSame('charge.paid', $webhook->stw_evento);
        $this->assertSame('hook_j0R8BYh0dS123RQ34', $webhook->stw_hook_id);
        $this->assertSame('ch_NRPl6mouLuZ123FR4', $webhook->stw_charge_id);
        $this->assertSame('D3MGIQI835', $webhook->stw_order_code);
        $this->assertSame($venda->id, $webhook->stw_venda_id);
        $this->assertNull($webhook->stw_autenticado);
    }

    public function test_webhook_com_credenciais_invalidas_retorna_401_mas_persiste(): void
    {
        config(['services.stone.webhook_user' => 'stone', 'services.stone.webhook_password' => 'segredo']);

        $response = $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid(), [
            'Authorization' => 'Basic '.base64_encode('stone:errado'),
        ]);

        $response->assertUnauthorized();

        $webhook = StoneWebhook::sole();
        $this->assertFalse($webhook->stw_autenticado);
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

    public function test_webhook_sem_venda_correspondente_apenas_registra_o_evento(): void
    {
        config(['services.stone.webhook_user' => null, 'services.stone.webhook_password' => null]);

        $response = $this->postJson('/api/webhook/stone-connect', $this->payloadChargePaid());

        $response->assertOk();
        $this->assertSame(0, Venda::count());
        $this->assertNull(StoneWebhook::sole()->stw_venda_id);
    }
}
