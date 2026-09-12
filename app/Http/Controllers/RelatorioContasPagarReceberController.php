<?php

namespace App\Http\Controllers;

use App\Enums\TipoLancamento;
use App\Services\RelatorioContasPagarReceberService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Imprimir" do Relatório de Contas a Pagar/Receber — mesmo padrão de
 * PDFController (pedidoPDF, sessaoCaixaPDF etc.): abre uma janela só com a
 * view impressa, que dispara window.print() automaticamente ao carregar. Os
 * filtros chegam aqui via query string (?filters[...]=...), no mesmo shape
 * usado pela página Filament (App\Filament\Pages\RelatorioContasPagarReceber).
 */
class RelatorioContasPagarReceberController extends Controller
{
    public function imprimir(Request $request): View
    {
        $filtros = (array) $request->query('filters', []);
        $service = new RelatorioContasPagarReceberService($filtros);

        return view('relatorios.contas-pagar-receber', [
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
        ]);
    }
}
