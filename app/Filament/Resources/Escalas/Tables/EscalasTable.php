<?php

namespace App\Filament\Resources\Escalas\Tables;

use App\Enums\Ponto\TipoEscala;
use App\Filament\Resources\Escalas\Schemas\EscalaForm;
use App\Filament\Resources\Escalas\Support\DuplicarEscalaAction;
use App\Models\Escala;
use App\Models\EscalaDia;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EscalasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('dias')->withCount(['colaboradores' => fn ($q) => $q->emAberto()]))
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')->label('Nome')->searchable()->sortable(),
                TextColumn::make('tipo')->label('Tipo')->badge(),
                TextColumn::make('dias')
                    ->label('Dias de trabalho')
                    ->state(fn (Escala $record): string => $record->dias
                        ->reject(fn (EscalaDia $dia): bool => $dia->folga)
                        ->map(fn (EscalaDia $dia): string => mb_substr(EscalaForm::DIAS_SEMANA[$dia->dia_semana] ?? '?', 0, 3).' '.substr((string) $dia->entrada, 0, 5).'–'.substr((string) $dia->saida, 0, 5))
                        ->join(' · ') ?: 'Sem dias')
                    ->wrap(),
                TextColumn::make('colaboradores_count')->label('Colaboradores')->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('tipo')->label('Tipo')->options(TipoEscala::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    DuplicarEscalaAction::make(),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ]);
    }
}
