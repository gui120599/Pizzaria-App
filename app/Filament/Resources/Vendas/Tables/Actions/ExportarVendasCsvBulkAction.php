<?php

namespace App\Filament\Resources\Vendas\Tables\Actions;

use App\Models\Venda;
use App\Services\RelatorioVendasExportService;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exporta em CSV as vendas selecionadas (o filtro atual inteiro sai pela ação do cabeçalho). */
class ExportarVendasCsvBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('exportarVendasCsv')
            ->label('Exportar CSV')
            ->icon('heroicon-o-table-cells')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('view:relatorio_financeiro') ?? false)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, RelatorioVendasExportService $exportacao): StreamedResponse {
                $linhas = $exportacao->linhas(Venda::query()->whereKey($records->modelKeys()));

                return response()->streamDownload(
                    fn () => $exportacao->escrever($linhas),
                    'vendas-'.now()->format('Ymd-His').'.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }
}
