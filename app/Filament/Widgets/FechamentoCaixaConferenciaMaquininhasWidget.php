<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/** Vendas registradas no sistema × leitura informada no fechamento, por maquininha. */
class FechamentoCaixaConferenciaMaquininhasWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $diferenca = fn (string $coluna, string $rotulo): TextColumn => TextColumn::make($coluna)
            ->label($rotulo)
            ->money('BRL')
            ->color(fn (float $state): string => abs($state) < 0.01 ? 'success' : 'danger');

        return $table
            ->heading('Conferência das maquininhas')
            ->description('Sistema = vendas e fiado recebido na maquininha. Leitura do fechamento menos o saldo da abertura; o PIX CNPJ é comparado com o extrato informado no fechamento.')
            ->records(fn (): array => (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))
                ->conferenciaMaquininhas()
                ->keyBy('key')
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhuma sessão com fechamento no recorte')
            ->columns([
                TextColumn::make('sessao_id')
                    ->label('Sessão')
                    ->prefix('#'),
                TextColumn::make('maquininha')
                    ->label('Maquininha'),
                TextColumn::make('sistema')
                    ->label('Sistema')
                    ->money('BRL'),
                TextColumn::make('leitura')
                    ->label('Leitura')
                    ->money('BRL'),
                $diferenca('diferenca_debito', 'Dif. débito'),
                $diferenca('diferenca_credito', 'Dif. crédito'),
                $diferenca('diferenca_pix', 'Dif. Pix'),
                $diferenca('diferenca', 'Diferença')->weight('bold'),
            ]);
    }
}
