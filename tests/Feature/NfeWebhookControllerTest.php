<?php

namespace Tests\Feature;

use App\Models\NfeWebhook;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NfeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_sem_secret_configurado_persiste_e_atualiza_a_venda(): void
    {
        config(['services.nfeio.webhook_secret' => null]);
        $venda = Venda::create(['venda_status' => 'FINALIZADA', 'venda_id_nfe' => 'inv-123']);

        $response = $this->postJson('/api/webhook/nfe-status', [
            'id' => 'inv-123',
            'status' => 'Issued',
            'event' => 'consumerinvoice.Issued',
        ]);

        $response->assertOk();
        $this->assertSame('Issued', $venda->fresh()->venda_status_nfe);

        $webhook = NfeWebhook::first();
        $this->assertNotNull($webhook);
        $this->assertSame('inv-123', $webhook->nfw_invoice_id);
        $this->assertSame($venda->id, $webhook->nfw_venda_id);
        $this->assertNull($webhook->nfw_assinatura_valida);
        $this->assertSame('consumerinvoice.Issued', $webhook->nfw_evento);
    }

    public function test_webhook_com_secret_configurado_e_assinatura_invalida_retorna_403_mas_persiste(): void
    {
        config(['services.nfeio.webhook_secret' => 'segredo-teste']);

        $response = $this->postJson('/api/webhook/nfe-status', ['id' => 'inv-999', 'status' => 'Issued'], [
            'X-Hub-Signature' => 'assinatura-errada',
        ]);

        $response->assertForbidden();

        $webhook = NfeWebhook::first();
        $this->assertNotNull($webhook);
        $this->assertFalse($webhook->nfw_assinatura_valida);
    }

    public function test_webhook_com_secret_configurado_e_assinatura_valida_persiste_true(): void
    {
        config(['services.nfeio.webhook_secret' => 'segredo-teste']);

        $payload = json_encode(['id' => 'inv-777', 'status' => 'Issued']);
        $assinatura = hash_hmac('sha1', $payload, 'segredo-teste');

        $response = $this->call(
            'POST',
            '/api/webhook/nfe-status',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Hub-Signature' => $assinatura,
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload
        );

        $response->assertOk();

        $webhook = NfeWebhook::first();
        $this->assertNotNull($webhook);
        $this->assertTrue($webhook->nfw_assinatura_valida);
    }

    public function test_webhook_sem_venda_correspondente_apenas_registra_o_evento(): void
    {
        config(['services.nfeio.webhook_secret' => null]);

        $response = $this->postJson('/api/webhook/nfe-status', ['id' => 'inv-desconhecido', 'status' => 'Issued']);

        $response->assertOk();
        $this->assertSame(0, Venda::count());
        $this->assertSame(1, NfeWebhook::count());
        $this->assertNull(NfeWebhook::first()->nfw_venda_id);
    }
}
