<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Estorno total na maquininha de uma venda já finalizada desfaz a venda:
 * NFC-e cancelada, venda CANCELADA, estoque da venda direta devolvido e
 * pedidos de volta para o recebimento no PDV (sem devolver o estoque deles).
 */
class EstornoStoneCancelaVendaTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_ID = 'or_estorno_1';

    private const CHARGE_ID = 'ch_estorno_1';

    private SessaoCaixa $sessao;

    private User $operador;

    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stone.webhook_user' => null,
            'services.stone.webhook_password' => null,
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
            'logging.channels.stone' => ['driver' => 'single', 'path' => storage_path('logs/stone-test-estorno.log'), 'level' => 'debug'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.pagar.me/*' => Http::response([], 200),
            'api.nfse.io/*' => Http::response([], 200),
        ]);

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11.222.333/0001-81',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);

        $user = $this->operador = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 100,
            'sessaocaixa_saldo_final' => 100,
            'sessaocaixa_user_id' => $user->id,
        ]);
        $this->categoria = Categoria::create(['categoria_nome' => 'Bebidas']);
    }

    private function venda(float $total = 20.00): Venda
    {
        return Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_valor_itens' => $total,
            'venda_valor_total' => $total,
        ]);
    }

    private function produtoComEstoque(): Produto
    {
        return Produto::create([
            'produto_descricao' => 'Refrigerante Lata',
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_controla_estoque' => true,
            'produto_saldo_estoque' => 10,
            'produto_custo_medio' => 3,
        ]);
    }

    private function itemVenda(Venda $venda, Produto $produto, float $valor = 20.00): void
    {
        ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $venda->id,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => $valor,
            'item_venda_desconto' => 0,
            'item_venda_valor' => $valor,
            'item_venda_valor_base_calculo' => $valor,
            'item_venda_status' => 'INSERIDO',
        ]);
    }

    /** Cobra o valor na maquininha (webhook charge.paid) e finaliza a venda. */
    private function pagarNaStoneEFinalizar(Venda $venda, float $valor = 20.00): void
    {
        StonePedido::create([
            'stp_venda_id' => $venda->id,
            'stp_maquininha_id' => Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'maquininha_connect_ativo' => true, 'numero_serie' => '6N021234'])->id,
            'stp_opcaopagamento_id' => OpcoesPagamento::create([
                'opcaopag_nome' => 'Crédito Stone',
                'opcaopag_desc_nfe' => 'creditCard',
                'opcaopag_tipo_taxa' => 'N/A',
                'opcaopag_valor_percentual_taxa' => 0,
                'opcaopag_stone_integrada' => true,
            ])->id,
            'stp_order_id' => self::ORDER_ID,
            'stp_order_code' => 'ABC1',
            'stp_valor_solicitado' => $valor,
            'stp_status' => 'aguardando',
            'stp_modo' => 'direto',
        ]);

        $centavos = (int) round($valor * 100);
        $this->postJson('/api/webhook/stone-connect', [
            'id' => 'hook_paid',
            'type' => 'charge.paid',
            'data' => [
                'id' => self::CHARGE_ID, 'code' => '999', 'amount' => $centavos, 'paid_amount' => $centavos, 'status' => 'paid',
                'order' => ['id' => self::ORDER_ID, 'code' => 'ABC1'],
                'metadata' => ['authorization_code' => 'A1'],
            ],
        ])->assertOk();

        // Finalização acontece no PDV, com operador logado; o estorno chega
        // depois pelo webhook, sem ninguém logado.
        Auth::login($this->operador);
        $venda->refresh()->update(['venda_status' => 'FINALIZADA']);
        Auth::logout();
    }

    private function estornar(float $valor = 20.00): void
    {
        $centavos = (int) round($valor * 100);
        $this->postJson('/api/webhook/stone-connect', [
            'id' => 'hook_refund',
            'type' => 'charge.refunded',
            'data' => [
                'id' => self::CHARGE_ID, 'code' => '999', 'amount' => $centavos, 'canceled_amount' => $centavos, 'status' => 'canceled',
                'order' => ['id' => self::ORDER_ID, 'code' => 'ABC1'],
            ],
        ])->assertOk();
    }

    public function test_estorno_total_cancela_venda_devolve_estoque_e_cancela_nfce(): void
    {
        $venda = $this->venda();
        $produto = $this->produtoComEstoque();
        $this->itemVenda($venda, $produto);
        $this->pagarNaStoneEFinalizar($venda);
        $venda->update(['venda_id_nfe' => 'inv_123', 'venda_status_nfe' => 'Issued']);

        $this->assertSame(9.0, (float) $produto->fresh()->produto_saldo_estoque);

        $this->estornar();

        $venda->refresh();
        $this->assertSame('CANCELADA', $venda->venda_status);
        $this->assertSame('CancelamentoSolicitado', $venda->venda_status_nfe);
        $this->assertSame(10.0, (float) $produto->fresh()->produto_saldo_estoque);
        $this->assertSame(100.0, (float) $this->sessao->fresh()->sessaocaixa_saldo_final);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'consumerinvoices/inv_123'));
    }

    public function test_estorno_total_devolve_pedido_para_recebimento_sem_devolver_estoque_dele(): void
    {
        $venda = $this->venda();
        $produto = $this->produtoComEstoque();
        $pedido = Pedido::create(['pedido_status' => 'ENTREGUE', 'pedido_valor_total' => 20.00]);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 20.00,
            'item_pedido_valor' => 20.00,
            'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => $venda->id,
        ]);
        $this->itemVenda($venda, $produto);
        $this->pagarNaStoneEFinalizar($venda);

        $this->assertSame('FINALIZADO', $pedido->fresh()->pedido_status);
        $saldoAntes = (float) $produto->fresh()->produto_saldo_estoque;

        $this->estornar();

        $pedido->refresh();
        $this->assertSame('ENTREGUE', $pedido->pedido_status);
        $this->assertNull($pedido->pedido_venda_id);
        $this->assertNull(ItensPedido::where('item_pedido_pedido_id', $pedido->id)->value('item_pedido_venda_id'));
        $this->assertSame($saldoAntes, (float) $produto->fresh()->produto_saldo_estoque);
    }

    public function test_estorno_com_outro_pagamento_na_venda_nao_cancela(): void
    {
        $venda = $this->venda(30.00);
        $this->itemVenda($venda, $this->produtoComEstoque(), 30.00);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => OpcoesPagamento::create([
                'opcaopag_nome' => 'Dinheiro',
                'opcaopag_desc_nfe' => 'cash',
                'opcaopag_tipo_taxa' => 'N/A',
                'opcaopag_valor_percentual_taxa' => 0,
            ])->id,
            'pg_venda_valor_pagamento' => 10.00,
        ]);
        $venda->update(['venda_valor_pago' => 10.00]);
        $this->pagarNaStoneEFinalizar($venda);

        $this->estornar();

        $this->assertSame('FINALIZADA', $venda->fresh()->venda_status);
        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $venda->id)->count());
    }
}
