<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComExcecoesPedidos;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Cancelamentos cruzando quem lançou o pedido e quem o cancelou. Contagem
 * para acompanhamento, em ordem alfabética.
 */
class ExcecoesCancelamentosPorUsuarioWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComExcecoesPedidos;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Cancelamentos: quem lançou × quem cancelou')
            ->description('Pela data do cancelamento. "Sem atendente" são pedidos do cardápio digital.')
            ->records(fn (): array => $this->relatorio()->cancelamentosPorUsuario()->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum cancelamento no período')
            ->columns([
                TextColumn::make('lancou')
                    ->label('Lançou o pedido'),
                TextColumn::make('cancelou')
                    ->label('Cancelou'),
                TextColumn::make('quantidade')
                    ->label('Cancelamentos')
                    ->numeric(),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL'),
            ]);
    }
}
