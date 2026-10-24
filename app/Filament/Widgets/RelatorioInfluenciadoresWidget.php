<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Services\RelatorioInfluenciadoresService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/** Linhas do Relatório de Influenciadores (dados calculados em PHP, por isso records()). */
class RelatorioInfluenciadoresWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Por influenciador')
            ->records(function (): array {
                [$inicio, $fim] = $this->periodo();

                return (new RelatorioInfluenciadoresService($inicio, $fim))->linhas()->keyBy('id')->all();
            })
            ->paginated(false)
            ->emptyStateHeading('Nenhum movimento do programa no período')
            ->columns([
                TextColumn::make('influenciador')->label('Influenciador'),
                TextColumn::make('cliques')->label('Cliques')->numeric(),
                TextColumn::make('pedidos')->label('Pedidos pagos')->numeric(),
                TextColumn::make('vendas')->label('Vendas líquidas')->money('BRL'),
                TextColumn::make('descontos')->label('Descontos de cupom')->money('BRL')->color('danger'),
                TextColumn::make('comissoes')->label('Comissões creditadas')->money('BRL'),
                TextColumn::make('resgates_pix')->label('Resgates Pix')->money('BRL'),
                TextColumn::make('consumo')->label('Consumo em pizza')->money('BRL'),
                TextColumn::make('saldo')->label('Saldo a pagar')->money('BRL')->weight('bold'),
            ]);
    }
}
