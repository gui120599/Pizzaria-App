<?php

namespace App\Filament\Resources\PrazosPagamento\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PrazosPagamentoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('parcelas')
                ->withSum('parcelas', 'parcela_percentual'))
            ->defaultSort('prazo_pagamento_nome')
            ->columns([
                TextColumn::make('prazo_pagamento_nome')
                    ->label('Nome')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('parcelas_count')
                    ->label('Parcelas')
                    ->alignCenter(),
                TextColumn::make('parcelas_sum_parcela_percentual')
                    ->label('Soma %')
                    ->formatStateUsing(fn (?string $state): string => number_format((float) $state, 2, ',', '.').'%')
                    ->alignCenter(),
                IconColumn::make('prazo_pagamento_ativo')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('prazo_pagamento_observacoes')
                    ->label('Observações')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('prazo_pagamento_ativo')
                    ->label('Ativo'),
                TrashedFilter::make(),
            ])
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
