<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Services\EntregaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EntregaScanController extends Controller
{
    public function show(Request $request, Pedido $pedido, EntregaService $service): View
    {
        $acao = $service->proximaAcao($pedido, $request->user());

        return view('app.entregador.scan', [
            'pedido' => $pedido,
            'acao' => $acao,
            'motivo' => $acao === 'indisponivel' ? $service->motivoIndisponivel($pedido, $request->user()) : null,
        ]);
    }

    public function confirmar(Request $request, Pedido $pedido, EntregaService $service): View
    {
        $acaoPretendida = $service->proximaAcao($pedido, $request->user());

        $sucesso = match ($acaoPretendida) {
            'aceitar' => $service->aceitar($pedido->id, $request->user()),
            'entregar' => $service->marcarEntregue($pedido->id, $request->user()),
            default => false,
        };

        $pedido->refresh();

        $resultado = match (true) {
            $sucesso && $acaoPretendida === 'aceitar' => 'saiu',
            $sucesso && $acaoPretendida === 'entregar' => 'entregue',
            default => 'erro',
        };

        return view('app.entregador.scan', [
            'pedido' => $pedido,
            'acao' => $resultado,
            'motivo' => $resultado === 'erro' ? $service->motivoIndisponivel($pedido, $request->user()) : null,
        ]);
    }
}
