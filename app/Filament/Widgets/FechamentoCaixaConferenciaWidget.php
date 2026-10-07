<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/** Esperado (recalculado) × apurado no fechamento de cada sessão. */
class FechamentoCaixaConferenciaWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Conferência por sessão')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->conferencia()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhuma sessão de caixa no recorte')
            ->columns([
                TextColumn::make('sessao_id')
                    ->label('Sessão')
                    ->prefix('#'),
                TextColumn::make('caixa')
                    ->label('Caixa'),
                TextColumn::make('operador')
                    ->label('Operador'),
                TextColumn::make('abertura')
                    ->label('Abertura')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('fechamento')
                    ->label('Fechamento')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Conferência')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Confirmado' => 'success',
                        'Rascunho' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('esperado')
                    ->label('Esperado')
                    ->money('BRL'),
                TextColumn::make('apurado')
                    ->label('Apurado')
                    ->money('BRL')
                    ->placeholder('—'),
                TextColumn::make('diferenca_dinheiro')
                    ->label('Dif. dinheiro')
                    ->money('BRL')
                    ->placeholder('—')
                    ->color(fn (?float $state): ?string => $state === null ? null : (abs($state) < 0.01 ? 'success' : 'danger')),
                TextColumn::make('diferenca')
                    ->label('Diferença')
                    ->money('BRL')
                    ->placeholder('—')
                    ->color(fn (?float $state): ?string => $state === null ? null : (abs($state) < 0.01 ? 'success' : 'danger'))
                    ->weight('bold'),
            ]);
    }
}
