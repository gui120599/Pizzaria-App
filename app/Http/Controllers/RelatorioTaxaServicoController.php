<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RelatorioTaxaServicoService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Imprimir" do Relatório de Taxa de Serviço — mesmo padrão de
 * RelatorioContasPagarReceberController: os filtros chegam via query string
 * (?filters[...]=...) no shape da página App\Filament\Pages\RelatorioTaxaServico.
 */
class RelatorioTaxaServicoController extends Controller
{
    public function imprimir(Request $request): View
    {
        $filtros = (array) $request->query('filters', []);
        $service = new RelatorioTaxaServicoService($filtros);

        return view('relatorios.taxa-servico', [
            'periodo' => $service->periodo(),
            'garcomFiltrado' => ! empty($filtros['garcom_id']) ? User::find($filtros['garcom_id'])?->name : null,
            'totais' => $service->totais(),
            'porGarcom' => $service->porGarcom(),
            'porVenda' => $service->porVenda(),
            'atribuicao' => RelatorioTaxaServicoService::ATRIBUICOES[$service->atribuicao()],
            'geradoEm' => now(),
            'geradoPor' => $request->user()?->name ?? 'Sistema',
        ]);
    }
}
