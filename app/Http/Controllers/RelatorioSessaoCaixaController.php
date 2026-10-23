<?php

namespace App\Http\Controllers;

use App\Models\Caixa;
use App\Services\RelatorioSessaoCaixaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Imprimir" do Relatório de Sessão de Caixa — mesmo padrão de
 * RelatorioFechamentoCaixaController: os filtros chegam via query string
 * (?filters[...]=...) no shape da página App\Filament\Pages\RelatorioSessaoCaixa.
 */
class RelatorioSessaoCaixaController extends Controller
{
    public function imprimir(Request $request): View
    {
        $filtros = (array) $request->query('filters', []);
        $service = new RelatorioSessaoCaixaService($filtros);
        $fechamento = $service->fechamento();

        return view('relatorios.sessao-caixa', [
            'periodo' => $fechamento->periodo(),
            'sessoesSelecionadas' => $fechamento->sessoesSelecionadas(),
            'caixaFiltrado' => ! empty($filtros['caixa_id']) ? Caixa::withTrashed()->find($filtros['caixa_id'])?->caixa_nome : null,
            'resumo' => $service->resumo(),
            'vendas' => $service->vendas(),
            'fiadoGerado' => $service->fiadoGerado(),
            'fiadoRecebido' => $service->fiadoRecebido(),
            'cortesias' => $service->cortesias(),
            'fluxo' => $fechamento->fluxoCaixa(),
            'conferenciaMaquininhas' => $fechamento->conferenciaMaquininhas(),
            'trocoRecolhimento' => $fechamento->trocoERecolhimento(),
            'geradoEm' => now(),
            'geradoPor' => $request->user()?->name ?? 'Sistema',
        ]);
    }
}
