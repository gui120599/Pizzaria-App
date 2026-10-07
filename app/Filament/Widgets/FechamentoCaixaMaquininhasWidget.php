<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Maquininha × tipo × bandeira, com a taxa do retrato do pagamento (ou a
 * atual, quando o pagamento não tem retrato).
 */
class FechamentoCaixaMaquininhasWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Maquininhas por bandeira')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->maquininhas()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum pagamento em maquininha no recorte')
            ->columns([
                TextColumn::make('maquininha')
                    ->label('Maquininha'),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Débito' => 'info',
                        'Crédito' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('bandeira')
                    ->label('Bandeira'),
                TextColumn::make('transacoes')
                    ->label('Transações')
                    ->numeric(),
                TextColumn::make('bruto')
                    ->label('Bruto')
                    ->money('BRL'),
                TextColumn::make('ticket_medio')
                    ->label('Ticket médio')
                    ->money('BRL'),
                TextColumn::make('taxa_percentual')
                    ->label('Taxa')
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2, ',', '.').'%')
                    ->description(fn (array $record): ?string => $record['sem_taxa'] > 0 ? "{$record['sem_taxa']} sem taxa cadastrada" : null)
                    ->color(fn (array $record): ?string => $record['sem_taxa'] > 0 ? 'warning' : null),
                TextColumn::make('taxa')
                    ->label('Valor da taxa')
                    ->money('BRL')
                    ->color('danger'),
                TextColumn::make('liquido')
                    ->label('Líquido')
                    ->money('BRL')
                    ->weight('bold'),
                TextColumn::make('prazo')
                    ->label('Recebe em'),
            ]);
    }
}
