<?php

namespace Tests\Feature;

use App\Models\PagamentosVenda;
use App\Models\StonePedido;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoneConciliarPedidosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
        ]);
        Http::preventStrayRequests();
    }

    private function pedido(array $attrs = []): StonePedido
    {
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 20, 'venda_valor_pago' => 0]);
        $criadoEm = $attrs['created_at'] ?? now();
        unset($attrs['created_at']);

        $pedido = StonePedido::create(array_merge([
            'stp_venda_id' => $venda->id,
            'stp_order_id' => 'or_test',
            'stp_order_code' => 'CODE1',
            'stp_valor_solicitado' => 20.00,
            'stp_status' => 'aguardando',
        ], $attrs));

        StonePedido::withoutTimestamps(fn () => $pedido->forceFill(['created_at' => $criadoEm])->save());

        return $pedido->fresh();
    }

    public function test_fecha_pedidos_pagos_ainda_nao_fechados(): void
    {
        Http::fake(['api.pagar.me/*/closed' => Http::response([], 200)]);
        $pedido = $this->pedido(['stp_status' => 'pago', 'stp_valor_pago' => 20.00]);

        $this->artisan('stone:conciliar-pedidos')->assertExitCode(0);

        $this->assertNotNull($pedido->fresh()->stp_fechado_em);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/closed'));
    }

    public function test_falha_no_fechamento_nao_derruba_o_comando(): void
    {
        Http::fake(['api.pagar.me/*/closed' => Http::response(['message' => 'x'], 500)]);
        $pedido = $this->pedido(['stp_status' => 'pago', 'stp_valor_pago' => 20.00]);

        $this->artisan('stone:conciliar-pedidos')->assertExitCode(0);

        $this->assertNull($pedido->fresh()->stp_fechado_em);
    }

    public function test_recupera_charge_paid_perdido_de_pedido_antigo(): void
    {
        $pedido = $this->pedido(['created_at' => now()->subMinutes(30)]);

        Http::fake([
            'api.pagar.me/core/v5/orders/or_test' => Http::response([
                'id' => 'or_test', 'code' => 'CODE1', 'status' => 'pending',
                'charges' => [[
                    'id' => 'ch_lost', 'code' => 'NSU9', 'amount' => 2000, 'paid_amount' => 2000, 'status' => 'paid',
                    'last_transaction' => ['metadata' => ['authorization_code' => 'Z9']],
                ]],
            ], 200),
            'api.pagar.me/*/closed' => Http::response([], 200),
        ]);

        $this->artisan('stone:conciliar-pedidos')->assertExitCode(0);

        $pedido->refresh();
        $this->assertSame('pago', $pedido->stp_status->value);
        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $pedido->stp_venda_id)->count());
    }

    public function test_pedido_antigo_cancelado_na_stone_vira_cancelado(): void
    {
        $pedido = $this->pedido(['created_at' => now()->subMinutes(30)]);

        Http::fake([
            'api.pagar.me/core/v5/orders/or_test' => Http::response(['id' => 'or_test', 'status' => 'canceled'], 200),
        ]);

        $this->artisan('stone:conciliar-pedidos')->assertExitCode(0);

        $this->assertSame('cancelado', $pedido->fresh()->stp_status->value);
    }

    public function test_pedido_aguardando_ha_mais_de_2h_vira_falha(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['id' => 'or_test', 'status' => 'pending', 'charges' => []], 200)]);
        $pedido = $this->pedido(['created_at' => now()->subHours(3)]);

        $this->artisan('stone:conciliar-pedidos')->assertExitCode(0);

        $this->assertSame('falha', $pedido->fresh()->stp_status->value);
    }
}
