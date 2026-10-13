<?php

namespace App\Filament\Resources\ChecklistNaoConformidades\Tables;

use App\Enums\Checklist\NaoConformidadeStatus;
use App\Models\ChecklistNaoConformidade;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChecklistNaoConformidadesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['execucaoItem.execucao', 'responsavel']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('execucaoItem.titulo')
                    ->label('Item')
                    ->description(fn (ChecklistNaoConformidade $record): string => $record->execucaoItem->execucao->modelo_nome)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('valor_registrado')
                    ->label('Registrado')
                    ->state(fn (ChecklistNaoConformidade $record): ?string => $record->execucaoItem->respostaFormatada())
                    ->placeholder('—'),
                IconColumn::make('critica')
                    ->label('Crítica')
                    ->boolean()
                    ->trueIcon('heroicon-s-exclamation-triangle')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray')
                    ->alignCenter(),
                TextColumn::make('responsavel.name')
                    ->label('Responsável')
                    ->placeholder('—'),
                TextColumn::make('prazo')
                    ->label('Prazo')
                    ->date('d/m/Y')
                    ->color(fn (ChecklistNaoConformidade $record): ?string => $record->vencida() ? 'danger' : null)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(NaoConformidadeStatus::class)
                    ->multiple()
                    ->default([NaoConformidadeStatus::Aberta->value, NaoConformidadeStatus::EmAndamento->value]),
                TernaryFilter::make('critica')
                    ->label('Crítica'),
                Filter::make('vencidas')
                    ->label('Só vencidas')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->vencidas()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Tratar'),
            ]);
    }
}
