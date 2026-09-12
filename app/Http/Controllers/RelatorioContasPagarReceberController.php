<?php

namespace App\Http\Controllers;

use App\Enums\TipoLancamento;
use App\Services\RelatorioContasPagarReceberService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Spatie\Browsershot\Browsershot;

/**
 * "Visualizar" e "Gerar PDF" do Relatório de Contas a Pagar/Receber. Os dois
 * reaproveitam exatamente a mesma view (resources/views/relatorios/contas-pagar-receber.blade.php)
 * e o mesmo RelatorioContasPagarReceberService usado pelos widgets do Filament
 * (App\Filament\Pages\RelatorioContasPagarReceber) — os filtros chegam aqui via
 * query string (?filters[...]=...), no mesmo shape usado pela página.
 */
class RelatorioContasPagarReceberController extends Controller
{
    public function imprimir(Request $request): View
    {
        return view('relatorios.contas-pagar-receber', $this->dados($request));
    }

    public function pdf(Request $request): Response
    {
        $html = view('relatorios.contas-pagar-receber', $this->dados($request))->render();

        $pdf = Browsershot::html($html)
            ->noSandbox()
            ->format('A4')
            ->margins(20, 15, 20, 15)
            ->showBackground()
            ->headerHtml('<div></div>')
            ->footerHtml('
                <div style="width:100%; font-size:9px; text-align:center; color:#666;">
                    Página <span class="pageNumber"></span> de <span class="totalPages"></span>
                </div>
            ')
            ->showBrowserHeaderAndFooter()
            ->pdf();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="relatorio-contas-pagar-receber-'.now()->format('Y-m-d_His').'.pdf"',
        ]);
    }

    /** @return array<string, mixed> */
    private function dados(Request $request): array
    {
        $filtros = (array) $request->query('filters', []);
        $service = new RelatorioContasPagarReceberService($filtros);

        return [
            'service' => $service,
            'filtros' => $filtros,
            'periodo' => $service->periodo(),
            'pagar' => $service->totaisAbertoEVencido(TipoLancamento::Pagar),
            'receber' => $service->totaisAbertoEVencido(TipoLancamento::Receber),
            'agingVencidosPagar' => $service->agingVencidos(TipoLancamento::Pagar),
            'agingAVencerPagar' => $service->agingAVencer(TipoLancamento::Pagar),
            'agingVencidosReceber' => $service->agingVencidos(TipoLancamento::Receber),
            'agingAVencerReceber' => $service->agingAVencer(TipoLancamento::Receber),
            'topFornecedores' => $service->topFavorecidos(TipoLancamento::Pagar, 10),
            'topClientes' => $service->topFavorecidos(TipoLancamento::Receber, 10),
            'pmp' => $service->pmp(),
            'pmr' => $service->pmr(),
            'cicloFinanceiro' => $service->cicloFinanceiro(),
            'saldoProjetadoPorFaixa' => $service->saldoProjetadoPorFaixa(),
            'lancamentosDetalhados' => $service->lancamentosDetalhados(),
            'geradoEm' => now(),
            'geradoPor' => $request->user()?->name ?? 'Sistema',
        ];
    }
}
