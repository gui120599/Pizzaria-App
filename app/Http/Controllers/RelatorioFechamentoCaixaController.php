<?php

namespace App\Http\Controllers;

use App\Models\Caixa;
use App\Services\RelatorioFechamentoCaixaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Imprimir" do Relatório de Fechamento de Caixa — mesmo padrão de
 * RelatorioTaxaServicoController: os filtros chegam via query string
 * (?filters[...]=...) no shape da página App\Filament\Pages\RelatorioFechamentoCaixa.
 */
class RelatorioFechamentoCaixaController extends Controller
{
    public function imprimir(Request $request): View
    {
        $filtros = (array) $request->query('filters', []);
        $service = new RelatorioFechamentoCaixaService($filtros);

        return view('relatorios.fechamento-caixa', [
            'periodo' => $service->periodo(),
            'sessoesSelecionadas' => $service->sessoesSelecionadas(),
            'caixaFiltrado' => ! empty($filtros['caixa_id']) ? Caixa::withTrashed()->find($filtros['caixa_id'])?->caixa_nome : null,
            'resumo' => $service->resumo(),
            'dre' => $service->dre(),
            'fluxo' => $service->fluxoCaixa(),
            'formas' => $service->porFormaPagamento(),
            'porMaquininha' => $service->porMaquininha(),
            'maquininhas' => $service->maquininhas(),
            'bandeiras' => $service->porBandeira(),
            'previsao' => $service->previsaoRecebimento(),
            'movimentacoes' => $service->movimentacoes(),
            'conferencia' => $service->conferencia(),
            'conferenciaMaquininhas' => $service->conferenciaMaquininhas(),
            'geradoEm' => now(),
            'geradoPor' => $request->user()?->name ?? 'Sistema',
        ]);
    }
}
