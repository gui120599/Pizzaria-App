<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;

/** Quando o líquido das maquininhas cai na conta (D+N do cadastro de taxas). */
class FechamentoCaixaPrevisaoRecebimentoWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Previsão de recebimento das maquininhas')
            ->description('Data da venda + prazo D+N (dias corridos) cadastrado nas taxas da maquininha.')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->previsaoRecebimento()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum pagamento em maquininha no recorte')
            ->columns([
                TextColumn::make('data')
                    ->label('Previsto para')
                    ->formatStateUsing(fn (?Carbon $state): string => $state?->format('d/m/Y') ?? 'Prazo não cadastrado')
                    ->placeholder('Prazo não cadastrado')
                    ->weight('bold'),
                TextColumn::make('maquininha')
                    ->label('Maquininha'),
                TextColumn::make('transacoes')
                    ->label('Transações')
                    ->numeric(),
                TextColumn::make('bruto')
                    ->label('Bruto')
                    ->money('BRL'),
                TextColumn::make('taxa')
                    ->label('Taxa')
                    ->money('BRL')
                    ->color('danger'),
                TextColumn::make('liquido')
                    ->label('A receber')
                    ->money('BRL')
                    ->weight('bold'),
            ]);
    }
}
