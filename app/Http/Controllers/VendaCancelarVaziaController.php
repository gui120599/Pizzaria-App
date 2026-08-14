<?php

namespace App\Http\Controllers;

use App\Enums\MotivoCancelamentoEnum;
use App\Models\Venda;
use App\Services\FinalizacaoVendaService;

/**
 * Endpoint leve, fora do ciclo de vida do Livewire, para o beforeunload +
 * sendBeacon do PDV (OperarVenda): sendBeacon é fire-and-forget e não
 * consegue disparar uma ação Livewire, só uma requisição HTTP simples.
 */
class VendaCancelarVaziaController extends Controller
{
    public function __invoke(Venda $venda, FinalizacaoVendaService $service)
    {
        if ($venda->venda_status === 'INICIADA' && (float) $venda->venda_valor_total <= 0) {
            $service->cancelar($venda, MotivoCancelamentoEnum::OUTRO->value);
        }

        return response()->noContent();
    }
}
