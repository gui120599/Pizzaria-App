<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComExcecoesPedidos;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Cancelamentos do período por motivo, com as últimas observações digitadas
 * (o motivo "Outro" exige texto). Dados agregados em PHP: usa records().
 */
class ExcecoesCancelamentosPorMotivoWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComExcecoesPedidos;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Cancelamentos por motivo')
            ->description('Pela data do cancelamento. As observações mostram as mais recentes, com o número do pedido.')
            ->records(fn (): array => $this->relatorio()->cancelamentosPorMotivo()->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum cancelamento no período')
            ->columns([
                TextColumn::make('motivo')
                    ->label('Motivo'),
                TextColumn::make('quantidade')
                    ->label('Cancelamentos')
                    ->numeric(),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL'),
                TextColumn::make('percentual')
                    ->label('% do valor cancelado')
                    ->formatStateUsing(fn (float $state): string => $this->pct($state)),
                TextColumn::make('observacoes')
                    ->label('Observações')
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->wrap(),
            ]);
    }
}
