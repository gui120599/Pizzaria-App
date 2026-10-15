<?php

namespace App\Filament\Resources\PontoPeriodos\Tables;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\StatusPeriodo;
use App\Filament\Resources\PontoPeriodos\PontoPeriodoResource;
use App\Models\PontoPeriodo;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PontoPeriodosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('repasses')->withSum('repasses', 'valor_liquido'))
            ->defaultSort('data_inicio', 'desc')
            ->recordUrl(fn (PontoPeriodo $record): string => PontoPeriodoResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('data_inicio')->label('Período')->state(fn (PontoPeriodo $record): string => $record->rotulo())->sortable(),
                TextColumn::make('periodicidade')->label('Periodicidade')->badge()->color('gray'),
                TextColumn::make('status')->label('Situação')->badge(),
                TextColumn::make('data_pagamento')->label('Pagamento')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('repasses_count')->label('Repasses')->alignCenter(),
                TextColumn::make('repasses_sum_valor_liquido')->label('Líquido')->money('BRL')->alignEnd()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Situação')->options(StatusPeriodo::class),
                SelectFilter::make('periodicidade')->label('Periodicidade')->options(PeriodicidadeRepasse::class),
            ])
            ->recordActions([
                EditAction::make()->label('Abrir'),
            ]);
    }
}
