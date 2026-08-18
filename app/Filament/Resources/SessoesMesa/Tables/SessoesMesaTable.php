<?php

namespace App\Filament\Resources\SessoesMesa\Tables;

use App\Enums\StatusSessaoMesa;
use App\Filament\Resources\SessoesMesa\Support\FecharSessaoMesaAction;
use App\Filament\Resources\SessoesMesa\Support\ReabrirSessaoMesaAction;
use App\Filament\Resources\SessoesMesa\Support\TrocarMesaAction;
use App\Models\SessaoMesa;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SessoesMesaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('pedidos', 'pedido_valor_total')->with(['mesa', 'cliente', 'garcom']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('mesa.mesa_nome')
                    ->label('Mesa')
                    ->searchable(),
                TextColumn::make('garcom.name')
                    ->label('Garçom')
                    ->searchable(),
                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Abertura')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('sessao_mesa_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusSessaoMesa::from($state)->getLabel())
                    ->color(fn (string $state): string => StatusSessaoMesa::from($state)->getColor()),
                TextColumn::make('pedidos_sum_pedido_valor_total')
                    ->label('Valor total')
                    ->money('BRL'),
            ])
            ->filters([
                SelectFilter::make('sessao_mesa_status')
                    ->label('Status')
                    ->options(StatusSessaoMesa::class),
                SelectFilter::make('sessao_mesa_mesa_id')
                    ->label('Mesa')
                    ->relationship('mesa', 'mesa_nome'),
            ])
            ->recordActions([
                FecharSessaoMesaAction::make(),
                ReabrirSessaoMesaAction::make(),
                TrocarMesaAction::make(),
                Action::make('imprimir')
                    ->label('Imprimir')
                    ->icon('heroicon-o-printer')
                    ->url(fn (SessaoMesa $record): string => route('sessaoMesa.imprimir', $record))
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
