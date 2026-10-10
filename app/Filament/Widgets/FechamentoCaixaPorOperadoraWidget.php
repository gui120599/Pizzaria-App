<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/** Recebimentos por marca (operadora) da maquininha, com a taxa abatida. */
class FechamentoCaixaPorOperadoraWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Por marca da maquininha')
            ->description('Vendas e fiado recebido somados por operadora (Stone, Cielo...). A marca vem do cadastro da maquininha.')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->porOperadora()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum pagamento em maquininha no recorte')
            ->columns([
                TextColumn::make('operadora')
                    ->label('Marca')
                    ->weight('bold')
                    ->description(fn (array $record): string => $record['maquininhas']),
                TextColumn::make('transacoes')
                    ->label('Transações')
                    ->numeric(),
                TextColumn::make('vendas')
                    ->label('Vendas')
                    ->money('BRL'),
                TextColumn::make('fiado')
                    ->label('Fiado recebido')
                    ->money('BRL'),
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
                TextColumn::make('participacao')
                    ->label('Participação')
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1, ',', '.').'%'),
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
