<?php

namespace App\Filament\Resources\Compras\Tables;

use App\Enums\CompraStatusEnum;
use App\Models\Compra;
use App\Services\CompraService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

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
                    ->color('gray')
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
                Action::make('confirmar')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Compra $record): bool => $record->isRascunho())
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar compra')
                    ->modalDescription('Gera as entradas de estoque e recalcula o custo médio. Esta ação não pode ser desfeita.')
                    ->action(function (Compra $record) {
                        try {
                            app(CompraService::class)->confirmar($record);
                            Notification::make()->title('Compra confirmada')->body('Estoque atualizado.')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('Não foi possível confirmar')->body(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
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
