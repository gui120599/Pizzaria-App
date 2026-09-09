<?php

namespace Tests\Unit\Services\Stone;

use App\Exceptions\StoneConnectException;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\StonePedido;
use App\Models\Venda;
use App\Services\Stone\StoneConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoneConnectServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-parceiro-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
            'services.stone.pedido_direto' => true,
        ]);
    }

    private function pedido(float $valor = 25.00, string $modo = 'listado', ?string $descNfe = null): StonePedido
    {
        $venda = Venda::create(['venda_status' => 'INICIADA']);
        $maquininha = Maquininha::create([
            'nome' => 'Balcão',
            'operadora' => 'stone',
            'numero_serie' => '6N021234',
        ]);

        $opcaoId = null;
        if ($descNfe !== null) {
            $opcaoId = OpcoesPagamento::create([
                'opcaopag_nome' => 'Stone '.$descNfe,
                'opcaopag_desc_nfe' => $descNfe,
                'opcaopag_tipo_taxa' => 'N/A',
                'opcaopag_valor_percentual_taxa' => 0,
                'opcaopag_stone_integrada' => true,
            ])->id;
        }

        return StonePedido::create([
            'stp_venda_id' => $venda->id,
            'stp_maquininha_id' => $maquininha->id,
            'stp_opcaopagamento_id' => $opcaoId,
            'stp_valor_solicitado' => $valor,
            'stp_status' => 'aguardando',
            'stp_modo' => $modo,
        ]);
    }

    public function test_criar_pedido_listado_monta_a_requisicao_correta(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_abc123', 'code' => 'XYZ9', 'status' => 'pending'], 200),
        ]);

        $pedido = $this->pedido(25.00);

        $resposta = app(StoneConnectService::class)->criarPedido($pedido);

        $this->assertSame('or_abc123', $resposta['id']);

        Http::assertSent(function ($request) use ($pedido) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.pagar.me/core/v5/orders'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_abc:'))
                && $request->hasHeader('ServiceRefererName', 'ref-parceiro-123')
                && $request->hasHeader('Idempotency-Key', "stone-pedido-{$pedido->id}")
                && $body['closed'] === false
                && $body['items'][0]['amount'] === 2500
                && $body['poi_payment_settings']['devices_serial_number'] === ['6N021234']
                && ! array_key_exists('payment_setup', $body['poi_payment_settings']);
        });
    }

    /**
     * @dataProvider tiposDireto
     */
    public function test_pedido_direto_inclui_payment_setup_com_o_tipo_da_forma(string $descNfe, string $tipoEsperado): void
    {
        Http::fake([
            'api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_d', 'code' => 'D1', 'status' => 'pending'], 200),
        ]);

        app(StoneConnectService::class)->criarPedido($this->pedido(25.00, 'direto', $descNfe));

        Http::assertSent(fn ($request) => ($request->data()['poi_payment_settings']['payment_setup'] ?? null) === [
            'type' => $tipoEsperado,
            'installments' => 1,
            'installment_type' => 'merchant',
        ]);
    }

    /** @return array<string, array{string, string}> */
    public static function tiposDireto(): array
    {
        return [
            'crédito' => ['creditCard', 'credit'],
            'débito' => ['debitCard', 'debit'],
            'pix' => ['InstantPayment', 'pix'],
        ];
    }

    public function test_pedido_direto_sem_tipo_stone_lanca_excecao(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);

        $this->expectException(StoneConnectException::class);

        app(StoneConnectService::class)->criarPedido($this->pedido(25.00, 'direto', 'cash'));
    }

    public function test_criar_pedido_com_erro_lanca_excecao_com_resposta(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/orders' => Http::response(['message' => 'terminal inválido'], 422),
        ]);

        try {
            app(StoneConnectService::class)->criarPedido($this->pedido());
            $this->fail('Deveria ter lançado StoneConnectException.');
        } catch (StoneConnectException $e) {
            $this->assertStringContainsString('terminal inválido', $e->getMessage());
            $this->assertSame(['message' => 'terminal inválido'], $e->respostaApi());
        }
    }

    public function test_fechar_pedido_faz_patch_no_endpoint_closed(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/orders/or_abc123/closed' => Http::response(['id' => 'or_abc123', 'closed' => true], 200),
        ]);

        app(StoneConnectService::class)->fecharPedido('or_abc123', 'paid');

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://api.pagar.me/core/v5/orders/or_abc123/closed'
            && $request->data() === ['status' => 'paid']);
    }

    public function test_cancelar_pedido_envia_status_canceled(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);

        app(StoneConnectService::class)->cancelarPedido('or_abc123');

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->data() === ['status' => 'canceled']);
    }

    public function test_consultar_pedido_faz_get(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/orders/or_abc123' => Http::response(['id' => 'or_abc123', 'status' => 'paid'], 200),
        ]);

        $dados = app(StoneConnectService::class)->consultarPedido('or_abc123');

        $this->assertSame('paid', $dados['status']);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.pagar.me/core/v5/orders/or_abc123');
    }

    public function test_sem_credenciais_configuradas_lanca_excecao(): void
    {
        config(['services.stone.secret_key' => null]);

        $this->expectException(StoneConnectException::class);

        app(StoneConnectService::class)->fecharPedido('or_abc123');
    }
}
