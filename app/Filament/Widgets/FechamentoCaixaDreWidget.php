<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/** Demonstrativo do resultado das vendas (DRE gerencial), em % do faturamento. */
class FechamentoCaixaDreWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Resultado das vendas (DRE)')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->dre()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('descricao')
                    ->label('')
                    ->weight(fn (array $record): ?FontWeight => $record['destaque'] ? FontWeight::Bold : null),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL')
                    ->alignEnd()
                    ->color(fn (array $record): ?string => $record['valor'] < 0 ? 'danger' : null)
                    ->weight(fn (array $record): ?FontWeight => $record['destaque'] ? FontWeight::Bold : null),
                TextColumn::make('percentual')
                    ->label('% do faturamento')
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2, ',', '.').'%')
                    ->alignEnd(),
            ]);
    }
}
