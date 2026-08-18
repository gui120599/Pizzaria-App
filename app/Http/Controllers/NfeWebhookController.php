<?php

namespace App\Http\Controllers;

use App\Models\NfeWebhook;
use App\Models\Venda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe os webhooks da NFe.io (POST /api/webhook/nfe-status). Antes só
 * fazia um Log::info + um UPDATE direto via DB::table sem guardar o payload
 * bruto — agora persiste cada evento em nfe_webhooks (ver Filament Resource
 * de inspeção) e resolve/atualiza a Venda vinculada via Eloquent.
 */
class NfeWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $data = $request->all();

        $venda = filled($data['id'] ?? null)
            ? Venda::where('venda_id_nfe', $data['id'])->first()
            : null;

        if ($venda && filled($data['status'] ?? null)) {
            $venda->update(['venda_status_nfe' => $data['status']]);
        }

        NfeWebhook::create([
            'nfw_evento' => $data['event'] ?? $data['type'] ?? null,
            'nfw_invoice_id' => $data['id'] ?? null,
            'nfw_venda_id' => $venda?->id,
            'nfw_payload' => $data,
            'nfw_assinatura_valida' => $request->attributes->get('nfe_assinatura_valida'),
            'nfw_processado_em' => now(),
        ]);

        if ($request->attributes->get('nfe_assinatura_valida') === false) {
            return response()->json(['message' => 'Assinatura inválida'], 403);
        }

        return response()->json(['message' => 'Atualizado com sucesso!']);
    }
}
