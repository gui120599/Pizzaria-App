<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioTaxaServicoService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Quanto cada garçom recebeu em cada venda (mesas da venda somadas).
 */
class TaxaServicoDetalhamentoWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Detalhamento por venda')
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $linhas = (new RelatorioTaxaServicoService($this->pageFilters ?? []))->porVenda();

                return new LengthAwarePaginator(
                    $linhas->forPage($page, $recordsPerPage)->keyBy('key')->all(),
                    $linhas->count(),
                    $recordsPerPage,
                    $page,
                );
            })
            ->emptyStateHeading('Nenhuma taxa de serviço no período')
            ->columns([
                TextColumn::make('finalizada_em')
                    ->label('Finalizada em')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('venda_id')
                    ->label('Venda')
                    ->prefix('#'),
                TextColumn::make('mesas')
                    ->label('Mesas'),
                TextColumn::make('garcom')
                    ->label('Garçom'),
                TextColumn::make('consumo')
                    ->label('Consumo')
                    ->money('BRL'),
                TextColumn::make('taxa')
                    ->label('Taxa')
                    ->money('BRL'),
            ]);
    }
}
