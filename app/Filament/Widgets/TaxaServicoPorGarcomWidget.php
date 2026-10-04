<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioTaxaServicoService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Total da taxa de serviço de cada garçom. Dados calculados em PHP (rateio
 * por rodada), então a tabela usa records() em vez de uma query Eloquent.
 */
class TaxaServicoPorGarcomWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Por garçom')
            ->records(fn (): array => (new RelatorioTaxaServicoService($this->pageFilters ?? []))
                ->porGarcom()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhuma taxa de serviço no período')
            ->columns([
                TextColumn::make('garcom')
                    ->label('Garçom'),
                TextColumn::make('mesas')
                    ->label('Mesas')
                    ->numeric(),
                TextColumn::make('vendas')
                    ->label('Vendas')
                    ->numeric(),
                TextColumn::make('consumo')
                    ->label('Consumo')
                    ->money('BRL'),
                TextColumn::make('taxa')
                    ->label('Taxa de serviço')
                    ->money('BRL')
                    ->weight('bold'),
            ]);
    }
}
