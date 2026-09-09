<?php

namespace App\Http\Controllers;

use App\Models\StoneWebhook;
use App\Models\Venda;
use App\Services\Stone\StoneRecebimentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe os webhooks do Connect Stone / Pagar.me v5
 * (POST /api/webhook/stone-connect). Eventos principais: `charge.paid` quando
 * o cliente paga o pedido na maquininha e `charge.refunded` no estorno.
 *
 * Sempre persiste o payload bruto em stone_webhooks primeiro (auditoria — ver
 * Filament Resource de inspeção) e só depois processa. A correlação com a
 * venda/pagamento é feita pelo StonePedido (stp_order_id), não pelo
 * `metadata.venda_id` (que a Stone às vezes sobrescreve).
 *
 * Falha no processamento é engolida e a resposta continua 200 de propósito:
 * um payload problemático não deve gerar retry infinito da Stone; a
 * recuperação fica no comando `stone:conciliar-pedidos`.
 */
class StoneWebhookController extends Controller
{
    public function handle(Request $request, StoneRecebimentoService $recebimento): JsonResponse
    {
        $data = $request->all();
        $charge = $data['data'] ?? [];
        $order = $charge['order'] ?? [];

        $pedido = $recebimento->resolverPedido($order);
        $venda = $pedido ? $pedido->venda : $this->resolverVendaLegado($order);

        $webhook = StoneWebhook::create([
            'stw_evento' => $data['type'] ?? null,
            'stw_hook_id' => $data['id'] ?? null,
            'stw_charge_id' => $charge['id'] ?? null,
            'stw_charge_code' => $charge['code'] ?? null,
            'stw_order_id' => $order['id'] ?? null,
            'stw_order_code' => $order['code'] ?? null,
            'stw_venda_id' => $venda?->id,
            'stw_stone_pedido_id' => $pedido?->id,
            'stw_payload' => $data,
            'stw_autenticado' => $request->attributes->get('stone_webhook_autenticado'),
            'stw_processado_em' => now(),
        ]);

        if ($request->attributes->get('stone_webhook_autenticado') === false) {
            Log::channel('stone')->warning('Webhook Stone recusado por Basic Auth inválido — evento NÃO processado (recuperação via stone:conciliar-pedidos)', [
                'hook_id' => $data['id'] ?? null,
                'evento' => $data['type'] ?? null,
                'charge_id' => $charge['id'] ?? null,
                'order_id' => $order['id'] ?? null,
                'stone_pedido_id' => $pedido?->id,
            ]);

            return response()->json(['message' => 'Credenciais inválidas'], 401);
        }

        try {
            $recebimento->processarWebhook($webhook);
        } catch (\Throwable $e) {
            Log::channel('stone')->error('Falha ao processar webhook Stone', [
                'hook_id' => $data['id'] ?? null,
                'evento' => $data['type'] ?? null,
                'erro' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Evento recebido']);
    }

    /** Fallback da Fase 1 — só usado quando não há StonePedido correlacionado. */
    private function resolverVendaLegado(array $order): ?Venda
    {
        $vendaId = $order['metadata']['venda_id'] ?? null;

        return filled($vendaId) ? Venda::find($vendaId) : null;
    }
}
