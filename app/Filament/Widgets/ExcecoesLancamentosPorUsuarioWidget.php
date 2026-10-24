<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComExcecoesPedidos;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Volume e exceções por quem lançou o pedido. Só contagens e valores, sem
 * nenhuma medida de tempo, em ordem alfabética (não ordena por "pior").
 */
class ExcecoesLancamentosPorUsuarioWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComExcecoesPedidos;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Lançamentos por usuário')
            ->description('Contagens para acompanhamento. Não reflete desempenho individual sem o contexto da operação (turno, função, tipo de atendimento).')
            ->records(fn (): array => $this->relatorio()->lancamentosPorUsuario()->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum pedido lançado no período')
            ->columns([
                TextColumn::make('usuario')
                    ->label('Usuário'),
                TextColumn::make('pedidos')
                    ->label('Pedidos lançados')
                    ->numeric(),
                TextColumn::make('valor')
                    ->label('Valor dos pedidos')
                    ->money('BRL'),
                TextColumn::make('ticket_medio')
                    ->label('Ticket médio')
                    ->money('BRL')
                    ->placeholder('—'),
                TextColumn::make('cancelados')
                    ->label('Cancelados (dos lançados)')
                    ->numeric(),
                TextColumn::make('itens_removidos')
                    ->label('Itens removidos (por ele)')
                    ->numeric(),
            ]);
    }
}
