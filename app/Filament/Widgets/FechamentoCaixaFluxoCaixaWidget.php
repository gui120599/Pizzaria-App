<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/** Abertura + vendas + fiado + suprimentos − saídas = esperado, × apurado no fechamento. */
class FechamentoCaixaFluxoCaixaWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Fluxo de caixa por forma de pagamento')
            ->description('Sessões do recorte. Apurado e diferença só das sessões que já têm fechamento.')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->fluxoCaixa()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhuma sessão de caixa no recorte')
            ->columns([
                TextColumn::make('categoria')
                    ->label('Forma')
                    ->weight(FontWeight::Bold),
                TextColumn::make('abertura')
                    ->label('Abertura')
                    ->money('BRL'),
                TextColumn::make('vendas')
                    ->label('Vendas')
                    ->money('BRL'),
                TextColumn::make('fiado')
                    ->label('Fiado recebido')
                    ->money('BRL'),
                TextColumn::make('suprimentos')
                    ->label('Suprimentos')
                    ->money('BRL'),
                TextColumn::make('saidas')
                    ->label('Saídas')
                    ->money('BRL')
                    ->color('danger'),
                TextColumn::make('esperado')
                    ->label('Esperado')
                    ->money('BRL')
                    ->weight(FontWeight::Bold),
                TextColumn::make('apurado')
                    ->label('Apurado')
                    ->money('BRL')
                    ->placeholder('Sem fechamento'),
                TextColumn::make('diferenca')
                    ->label('Diferença')
                    ->money('BRL')
                    ->placeholder('—')
                    ->color(fn (?float $state): ?string => $state === null ? null : (abs($state) < 0.01 ? 'success' : 'danger'))
                    ->weight(FontWeight::Bold),
            ]);
    }
}
