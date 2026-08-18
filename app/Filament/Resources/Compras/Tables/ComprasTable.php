<?php

namespace App\Filament\Resources\Compras\Tables;

use App\Enums\CompraStatusEnum;
use App\Filament\Resources\Compras\Support\ConfirmarCompraAction;
use App\Filament\Resources\Compras\Support\ImprimirDanfeAction;
use App\Filament\Resources\Compras\Support\RegistrarDevolucaoAction;
use App\Models\Compra;
use App\Models\PrestadorCredito;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComprasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('prestador')->withCount('itens'))
            ->defaultSort('compra_data_entrada', 'desc')
            ->columns([
                TextColumn::make('compra_numero')
                    ->label('NF')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('prestador.nome_exibicao')
                    ->label('Fornecedor')
                    ->searchable(['nome', 'razao_social'])
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('compra_data_entrada')
                    ->label('Entrada')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('itens_count')
                    ->label('Itens')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('compra_valor_total')
                    ->label('Total')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('compra_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (CompraStatusEnum $state): string => $state->label())
                    ->color(fn (CompraStatusEnum $state): string => $state->cor()),

                TextColumn::make('compra_origem')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'xml' ? 'XML' : 'Manual')
                    ->color(fn (string $state): string => $state === 'xml' ? 'info' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('credito_fornecedor')
                    ->label('Crédito c/ fornecedor')
                    ->state(fn (Compra $record): float => $record->compra_prestador_id
                        ? (float) PrestadorCredito::doPrestador($record->compra_prestador_id)->naoAplicados()->sum('valor')
                        : 0.0)
                    ->money('BRL')
                    ->color(fn (float $state): string => $state > 0 ? 'success' : 'gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('compra_status')
                    ->label('Status')
                    ->options(
                        collect(CompraStatusEnum::cases())
                            ->mapWithKeys(fn ($s) => [$s->value => $s->label()])
                            ->toArray()
                    ),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ConfirmarCompraAction::make()->label('Confirmar'),
                RegistrarDevolucaoAction::make(),
                ImprimirDanfeAction::make(),
                EditAction::make()
                    ->visible(fn (Compra $record): bool => $record->isRascunho()),
                DeleteAction::make()
                    ->visible(fn (Compra $record): bool => $record->isRascunho()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
