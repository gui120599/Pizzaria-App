<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/** Abertura, suprimentos, sangrias/despesas e fiado recebido nas sessões do recorte. */
class FechamentoCaixaMovimentacoesWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Movimentações de caixa')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->movimentacoes()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhuma movimentação nas sessões do recorte')
            ->columns([
                TextColumn::make('movimento')
                    ->label('Movimento'),
                TextColumn::make('forma')
                    ->label('Forma')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('quantidade')
                    ->label('Qtd.')
                    ->numeric(),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL')
                    ->color(fn (float $state): string => $state < 0 ? 'danger' : 'success')
                    ->weight('bold'),
            ]);
    }
}
