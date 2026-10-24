<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\PedidoStatusActions;
use App\Filament\Widgets\Concerns\InteractsComExcecoesPedidos;
use App\Models\Pedido;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Pedidos entregues que continuam sem pagamento, para o Admin e o Gerente
 * decidirem: cobrar ou cancelar o pedido entregue.
 */
class ExcecoesEntreguesNaoPagosWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComExcecoesPedidos;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Entregues sem pagamento')
            ->description('Pedidos abertos no período, entregues e sem venda vinculada.')
            ->query(fn () => $this->relatorio()->entreguesNaoPagos()->with(['cliente', 'garcom']))
            ->defaultSort('pedido_datahora_abertura')
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Nenhum pedido entregue sem pagamento')
            ->columns([
                TextColumn::make('id')
                    ->label('Pedido')
                    ->prefix('#')
                    ->url(fn (Pedido $record): string => PedidoResource::getUrl('view', ['record' => $record]))
                    ->sortable(),
                TextColumn::make('pedido_datahora_abertura')
                    ->label('Abertura')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente'),
                TextColumn::make('garcom.name')
                    ->label('Atendente'),
                TextColumn::make('pedido_valor_total')
                    ->label('Valor do pedido')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->recordActions([
                PedidoStatusActions::cancelarEntregueDaTabela(),
            ]);
    }
}
