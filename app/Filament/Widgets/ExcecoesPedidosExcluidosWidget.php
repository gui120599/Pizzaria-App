<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Widgets\Concerns\InteractsComExcecoesPedidos;
use App\Models\Pedido;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Pedidos excluídos (soft delete) no período: a exclusão nunca é física, e o
 * motivo e o autor ficam aqui para conferência.
 */
class ExcecoesPedidosExcluidosWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComExcecoesPedidos;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Pedidos excluídos')
            ->description('Pela data da exclusão.')
            ->query(fn () => $this->relatorio()->excluidos()->with('usuarioExcluiu'))
            ->defaultSort('deleted_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Nenhum pedido excluído no período')
            ->columns([
                TextColumn::make('id')
                    ->label('Pedido')
                    ->prefix('#')
                    ->url(fn (Pedido $record): string => PedidoResource::getUrl('view', ['record' => $record]))
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->label('Excluído em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('pedido_motivo_exclusao')
                    ->label('Motivo')
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? 'Não informado'),
                TextColumn::make('pedido_exclusao_observacao')
                    ->label('Observação')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('usuarioExcluiu.name')
                    ->label('Excluído por')
                    ->placeholder('Não informado'),
                TextColumn::make('pedido_valor_total')
                    ->label('Valor do pedido')
                    ->money('BRL')
                    ->sortable(),
            ]);
    }
}
