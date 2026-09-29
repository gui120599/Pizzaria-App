<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Models\User;
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
        $opcao = OpcoesPagamento::create([
            'opcaopag_nome' => 'Crédito Stone',
            'opcaopag_desc_nfe' => 'creditCard',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => true,
        ]);

        Http::fake([
            'api.pagar.me/core/v5/orders/or_test' => Http::response([
                'id' => 'or_test', 'code' => 'CODE1', 'status' => 'pending',
                'charges' => [[
                    'id' => 'ch_lost', 'code' => 'NSU9', 'amount' => 2000, 'paid_amount' => 2000, 'status' => 'paid',
                    'last_transaction' => ['transaction_type' => 'credit_card', 'metadata' => ['authorization_code' => 'Z9']],
                ]],
            ], 200),
            'api.pagar.me/*/closed' => Http::response([], 200),
        ]);

        $this->artisan('stone:conciliar-pedidos')->assertExitCode(0);

        $pedido->refresh();
        $this->assertSame('pago', $pedido->stp_status->value);
        $pagamento = PagamentosVenda::where('pg_venda_venda_id', $pedido->stp_venda_id)->sole();
        $this->assertSame($opcao->id, $pagamento->pg_venda_opcaopagamento_id);
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

    public function test_reprocessa_pedido_pago_sem_venda_quando_o_caixa_abre_depois(): void
    {
        Http::fake(['api.pagar.me/*/closed' => Http::response([], 200)]);

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 20.00,
        ]);
        $pedidoDelivery = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedidoDelivery->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 20.00,
            'item_pedido_valor' => 20.00,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        // Simula o que aconteceu no webhook: pago, mas sem sessão de caixa
        // aberta na hora — ficou com stp_erro e sem stp_venda_id.
        $stonePedido = StonePedido::create([
            'stp_pedido_id' => $pedidoDelivery->id,
            'stp_origem' => 'pedido',
            'stp_order_id' => 'or_sem_venda',
            'stp_order_code' => 'CODE2',
            'stp_valor_solicitado' => 20.00,
            'stp_valor_pago' => 20.00,
            'stp_status' => 'pago',
            'stp_modo' => 'listado',
            'stp_erro' => 'Não foi possível determinar a sessão de caixa: 0 sessão(ões) ABERTA(S).',
        ]);

        $this->artisan('stone:conciliar-pedidos')->assertExitCode(0);

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertSame($venda->id, $stonePedido->fresh()->stp_venda_id);
        $this->assertSame('FINALIZADO', $pedidoDelivery->fresh()->pedido_status);
        $this->assertSame(1, PagamentosVenda::count());
    }
}
