<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/** Total processado em cada maquininha, com a taxa abatida. */
class FechamentoCaixaPorMaquininhaWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Por maquininha')
            ->description('Pagamento sem maquininha informada conta na maquininha padrão.')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->porMaquininha()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum pagamento em maquininha no recorte')
            ->columns([
                TextColumn::make('maquininha')
                    ->label('Maquininha')
                    ->weight('bold'),
                TextColumn::make('transacoes')
                    ->label('Transações')
                    ->numeric(),
                TextColumn::make('debito')
                    ->label('Débito')
                    ->money('BRL'),
                TextColumn::make('credito')
                    ->label('Crédito')
                    ->money('BRL'),
                TextColumn::make('pix')
                    ->label('Pix')
                    ->money('BRL'),
                TextColumn::make('bruto')
                    ->label('Bruto')
                    ->money('BRL'),
                TextColumn::make('taxa_percentual')
                    ->label('Taxa média')
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2, ',', '.').'%'),
                TextColumn::make('taxa')
                    ->label('Taxa')
                    ->money('BRL')
                    ->color('danger'),
                TextColumn::make('liquido')
                    ->label('Líquido')
                    ->money('BRL')
                    ->weight('bold'),
            ]);
    }
}
