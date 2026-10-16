<?php

namespace App\Filament\Resources\Caixas\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CaixasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('caixa_nome')
            ->columns([
                TextColumn::make('caixa_nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('caixa_fechamento_obrigatorio')
                    ->label('Fechamento obrigatório')
                    ->boolean(),
            ])
            ->filters([
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
