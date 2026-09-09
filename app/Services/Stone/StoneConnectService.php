<?php

namespace App\Services\Stone;

use App\Enums\StonePedidoModo;
use App\Exceptions\StoneConnectException;
use App\Models\StonePedido;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cliente HTTP da API Connect Stone (roda sobre o Pagar.me v5,
 * api.pagar.me/core/v5). Cobre o que o fluxo de recebimento em maquininha
 * precisa: criar pedido (modelo Listado), fechar/cancelar pedido e consultar.
 *
 * Autenticação: HTTP Basic com a SecretKey como usuário e senha vazia
 * (padrão Pagar.me), mais o header ServiceRefererName (id do parceiro no
 * Stone Partner Hub). Segue o padrão de App\Services\NfeIoService: Http
 * facade, erro vira exception de domínio, testável com Http::fake().
 */
class StoneConnectService
{
    /**
     * @return array{secret_key: string, service_referer_name: string, base_url: string}
     */
    private function config(): array
    {
        $secret = config('services.stone.secret_key');
        $referer = config('services.stone.service_referer_name');

        if (blank($secret) || blank($referer)) {
            throw new StoneConnectException('Integração com a Stone Connect não está configurada (STONE_CONNECT_SECRET_KEY / STONE_CONNECT_SERVICE_REFERER_NAME ausentes).');
        }

        return [
            'secret_key' => $secret,
            'service_referer_name' => $referer,
            'base_url' => rtrim(config('services.stone.base_url') ?: 'https://api.pagar.me/core/v5', '/'),
        ];
    }

    private function client(): PendingRequest
    {
        ['secret_key' => $secret, 'service_referer_name' => $referer] = $this->config();

        return Http::withBasicAuth($secret, '')
            ->withHeaders(['ServiceRefererName' => $referer])
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * Cria um pedido na Stone (POST /orders, sempre com closed:false para o
     * pedido ir ao POS). O modo do StonePedido decide o modelo:
     *  - Direto: leva poi_payment_settings.payment_setup (tipo + parcelas) — a
     *    maquininha pula direto para a tela de pagamento daquele tipo.
     *  - Listado: sem payment_setup — o pedido entra na lista do POS e o
     *    operador/entregador seleciona e escolhe o tipo na maquininha.
     * Cada transação vira uma charge e dispara webhook charge.paid.
     *
     * @return array{id: string, code: string, status: string}
     */
    public function criarPedido(StonePedido $pedido): array
    {
        ['base_url' => $base] = $this->config();

        $pedido->loadMissing(['venda.cliente', 'pedido.cliente', 'maquininha', 'opcaoPagamento']);
        $cliente = $pedido->venda?->cliente ?? $pedido->pedido?->cliente;

        $nome = Str::limit(trim((string) ($cliente->cliente_nome ?? 'Consumidor')), 64, '');
        $email = filled($cliente->cliente_email ?? null)
            ? Str::limit($cliente->cliente_email, 64, '')
            : 'consumidor@exemplo.com';

        $rotulo = filled($pedido->stp_venda_id)
            ? "Venda #{$pedido->stp_venda_id}"
            : "Pedido #{$pedido->stp_pedido_id}";

        $body = [
            'customer' => [
                'name' => $nome !== '' ? $nome : 'Consumidor',
                'email' => $email,
            ],
            'items' => [[
                'amount' => (int) round((float) $pedido->stp_valor_solicitado * 100),
                'description' => $rotulo,
                'quantity' => 1,
            ]],
            'closed' => false,
            'poi_payment_settings' => [
                'visible' => true,
                'print_order_receipt' => false,
                'display_name' => $rotulo,
                'devices_serial_number' => array_values(array_filter([$pedido->maquininha?->numero_serie])),
            ],
        ];

        if ($pedido->stp_modo === StonePedidoModo::Direto) {
            $tipo = $pedido->opcaoPagamento?->tipoStone();
            if (blank($tipo)) {
                throw new StoneConnectException('Forma de pagamento sem tipo Stone (crédito/débito/PIX) para o Pedido Direto.');
            }

            $body['poi_payment_settings']['payment_setup'] = [
                'type' => $tipo,
                'installments' => 1,
                'installment_type' => 'merchant',
            ];
        }

        $response = $this->client()
            ->withHeaders(['Idempotency-Key' => "stone-pedido-{$pedido->id}"])
            ->post("{$base}/orders", $body);

        if ($response->failed()) {
            $this->logErro('criarPedido', $response->status(), $response->json(), ['stone_pedido_id' => $pedido->id]);
            throw new StoneConnectException(
                'Falha ao criar o pedido na Stone: '.($response->json('message') ?? $response->body()),
                $response->json()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Fecha o pedido na Stone. Status: 'paid' (padrão), 'canceled' ou 'failed'.
     * OBRIGATÓRIO após a confirmação de pagamento — sem isso a maquininha pode
     * parar de exibir novos pedidos (limite de 30 pedidos abertos).
     */
    public function fecharPedido(string $orderId, string $status = 'paid'): void
    {
        ['base_url' => $base] = $this->config();

        $response = $this->client()
            ->retry(2, 200, throw: false)
            ->patch("{$base}/orders/{$orderId}/closed", ['status' => $status]);

        if ($response->failed()) {
            $this->logErro('fecharPedido', $response->status(), $response->json(), ['order_id' => $orderId, 'status' => $status]);
            throw new StoneConnectException(
                "Falha ao fechar o pedido {$orderId} na Stone: ".($response->json('message') ?? $response->body()),
                $response->json()
            );
        }
    }

    /** Cancela o pedido antes do pagamento (retira da lista do POS). */
    public function cancelarPedido(string $orderId): void
    {
        $this->fecharPedido($orderId, 'canceled');
    }

    /**
     * Consulta um pedido (usado pelo comando de conciliação para recuperar
     * webhooks perdidos).
     */
    public function consultarPedido(string $orderId): array
    {
        ['base_url' => $base] = $this->config();

        $response = $this->client()
            ->retry(2, 200, throw: false)
            ->get("{$base}/orders/{$orderId}");

        if ($response->failed()) {
            $this->logErro('consultarPedido', $response->status(), $response->json(), ['order_id' => $orderId]);
            throw new StoneConnectException(
                "Falha ao consultar o pedido {$orderId} na Stone: ".($response->json('message') ?? $response->body()),
                $response->json()
            );
        }

        return $response->json() ?? [];
    }

    private function logErro(string $operacao, int $status, ?array $corpo, array $contexto = []): void
    {
        Log::channel('stone')->error("StoneConnect: {$operacao} falhou", array_merge($contexto, [
            'http_status' => $status,
            'resposta' => $corpo,
        ]));
    }
}
