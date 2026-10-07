<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

class FechamentoCaixaFormasPagamentoWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Por forma de pagamento')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->porFormaPagamento()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum pagamento no recorte')
            ->columns([
                TextColumn::make('forma')
                    ->label('Forma de pagamento'),
                TextColumn::make('categoria')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('transacoes')
                    ->label('Transações')
                    ->numeric(),
                TextColumn::make('bruto')
                    ->label('Bruto')
                    ->money('BRL'),
                TextColumn::make('participacao')
                    ->label('Mix')
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1, ',', '.').'%'),
                TextColumn::make('taxa')
                    ->label('Taxa maquininha')
                    ->money('BRL')
                    ->color('danger'),
                TextColumn::make('liquido')
                    ->label('Líquido')
                    ->money('BRL')
                    ->weight('bold'),
            ]);
    }
}
