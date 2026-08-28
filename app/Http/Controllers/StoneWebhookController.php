<?php

namespace App\Http\Controllers;

use App\Models\StoneWebhook;
use App\Models\Venda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe os webhooks do Connect Stone / Pagar.me v5
 * (POST /api/webhook/stone-connect). Eventos principais: `charge.paid` quando
 * o cliente paga o pedido na maquininha e `charge.refunded` no estorno.
 *
 * Por ora só persiste cada evento em stone_webhooks (ver Filament Resource de
 * inspeção) e tenta resolver a Venda vinculada pelo `metadata.venda_id` que o
 * PDV envia ao criar o pedido. O lançamento do pagamento na venda e o
 * fechamento do pedido (PATCH /core/v5/orders/{id}/closed) ficam para a etapa
 * do fluxo completo de recebimento.
 */
class StoneWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $data = $request->all();
        $charge = $data['data'] ?? [];
        $order = $charge['order'] ?? [];

        $venda = $this->resolverVenda($order);

        StoneWebhook::create([
            'stw_evento' => $data['type'] ?? null,
            'stw_hook_id' => $data['id'] ?? null,
            'stw_charge_id' => $charge['id'] ?? null,
            'stw_charge_code' => $charge['code'] ?? null,
            'stw_order_id' => $order['id'] ?? null,
            'stw_order_code' => $order['code'] ?? null,
            'stw_venda_id' => $venda?->id,
            'stw_payload' => $data,
            'stw_autenticado' => $request->attributes->get('stone_webhook_autenticado'),
            'stw_processado_em' => now(),
        ]);

        if ($request->attributes->get('stone_webhook_autenticado') === false) {
            return response()->json(['message' => 'Credenciais inválidas'], 401);
        }

        return response()->json(['message' => 'Evento recebido']);
    }

    private function resolverVenda(array $order): ?Venda
    {
        $vendaId = $order['metadata']['venda_id'] ?? null;

        return filled($vendaId) ? Venda::find($vendaId) : null;
    }
}
