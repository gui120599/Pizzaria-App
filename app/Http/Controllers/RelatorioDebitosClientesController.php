<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\DebitosClienteService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Imprimir" do Relatório de Débitos de Clientes e extrato de um cliente —
 * mesmo padrão de RelatorioTaxaServicoController: filtros via query string
 * (?filters[...]=...) no shape da página App\Filament\Pages\RelatorioDebitosClientes.
 * Abre para quem vê clientes ou para o caixa (aba Pendentes do PDV).
 */
class RelatorioDebitosClientesController extends Controller
{
    public function imprimir(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Cliente::class) || $user->can('operar:venda'), 403);

        $filtros = (array) $request->query('filters', []);
        $filtros['somente_vendas'] = filter_var($filtros['somente_vendas'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $service = new DebitosClienteService($filtros);

        return view('relatorios.debitos-clientes', [
            'porCliente' => $service->porCliente(),
            'totais' => $service->totais(),
            'clienteFiltrado' => ! empty($filtros['cliente_id']) ? Cliente::find($filtros['cliente_id'])?->cliente_nome : null,
            'somenteVencidos' => filter_var($filtros['somente_vencidos'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'agruparPorMes' => filter_var($filtros['agrupar_mes'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'busca' => trim((string) ($filtros['busca'] ?? '')),
            'geradoEm' => now(),
            'geradoPor' => $user->name ?? 'Sistema',
        ]);
    }
}
