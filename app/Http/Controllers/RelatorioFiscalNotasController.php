<?php

namespace App\Http\Controllers;

use App\Services\RelatorioFiscalNotasService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportações do Relatório fiscal (NFC-e): os filtros chegam por query string
 * (?inicio=&fim=&agrupamentos[]=) no shape de App\Filament\Pages\RelatorioFiscalNotas.
 */
class RelatorioFiscalNotasController extends Controller
{
    public function imprimir(Request $request): View
    {
        [$service, $dimensoes] = $this->montar($request);

        return view('relatorios.fiscal-notas', [
            'inicio' => Carbon::parse($request->query('inicio', now()->startOfMonth())),
            'fim' => Carbon::parse($request->query('fim', now())),
            'resumo' => $service->resumo(),
            'notas' => $service->notas(),
            'grupos' => collect($dimensoes)->mapWithKeys(fn (string $dimensao) => [$dimensao => $service->agrupar($dimensao)]),
            'dimensoes' => RelatorioFiscalNotasService::DIMENSOES,
            'geradoEm' => now(),
            'geradoPor' => $request->user()?->name ?? 'Sistema',
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$service, $dimensoes] = $this->montar($request);
        $linhas = $service->paraCsv($dimensoes);

        return response()->streamDownload(function () use ($linhas): void {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");

            foreach ($linhas as $linha) {
                fputcsv($saida, $linha, ';');
            }

            fclose($saida);
        }, 'relatorio-fiscal-nfce-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: RelatorioFiscalNotasService, 1: array<int, string>}
     */
    private function montar(Request $request): array
    {
        $service = new RelatorioFiscalNotasService(
            Carbon::parse($request->query('inicio', now()->startOfMonth())),
            Carbon::parse($request->query('fim', now())),
        );

        $dimensoes = array_values(array_intersect(
            array_keys(RelatorioFiscalNotasService::DIMENSOES),
            (array) $request->query('agrupamentos', []),
        ));

        return [$service, $dimensoes];
    }
}
