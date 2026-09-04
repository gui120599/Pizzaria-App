<?php

namespace App\Filament\Resources\Maquininhas\Tables;

use App\Enums\OperadoraMaquininha;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class MaquininhasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('operadora')
                    ->label('Operadora')
                    ->badge(),
                TextColumn::make('numero_serie')
                    ->label('Número de série')
                    ->searchable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('operadora')
                    ->label('Operadora')
                    ->options(OperadoraMaquininha::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
