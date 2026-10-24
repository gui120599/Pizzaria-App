<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComExcecoesPedidos;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Itens removidos de pedidos por quem os removeu. Itens antigos não guardam
 * o autor e entram em "Não informado".
 */
class ExcecoesItensRemovidosWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComExcecoesPedidos;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Itens removidos por usuário')
            ->description('Pela última alteração do item. Rascunhos não entram.')
            ->records(fn (): array => $this->relatorio()->itensRemovidosPorUsuario()->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum item removido no período')
            ->columns([
                TextColumn::make('usuario')
                    ->label('Removido por'),
                TextColumn::make('itens')
                    ->label('Itens')
                    ->numeric(),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL'),
            ]);
    }
}
