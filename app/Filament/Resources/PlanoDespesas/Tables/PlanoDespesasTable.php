<?php

namespace App\Filament\Resources\PlanoDespesas\Tables;

use App\Enums\Comportamento;
use App\Enums\Periodicidade;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlanoDespesasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager loading do grupo pai para evitar N+1
            ->modifyQueryUsing(fn (Builder $query) => $query->with('pai'))
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pai.nome')
                    ->label('Grupo')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('comportamento')
                    ->label('Comportamento')
                    ->badge(),
                TextColumn::make('periodicidade')
                    ->label('Periodicidade')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('comportamento')
                    ->label('Comportamento')
                    ->options(Comportamento::class),
                SelectFilter::make('periodicidade')
                    ->label('Periodicidade')
                    ->options(Periodicidade::class),
                TrashedFilter::make(),
            ])
            ->defaultSort('nome')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}
