<?php

namespace App\Filament\Resources\Adiantamentos\Tables;

use App\Enums\Ponto\NaturezaAdiantamento;
use App\Enums\Ponto\StatusAdiantamento;
use App\Filament\Resources\Adiantamentos\Support\RegistrarAdiantamentoAction;
use App\Models\Adiantamento;
use App\Models\Colaborador;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AdiantamentosTable
{
    /** Também usada pela aba de adiantamentos do colaborador (sem a coluna/filtro de colaborador). */
    public static function configure(Table $table, bool $doColaborador = false): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['colaborador', 'contaFinanceira', 'repasse.periodo', 'registradoPor']))
            ->defaultSort('data', 'desc')
            ->columns([
                TextColumn::make('data')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('colaborador.nome')->label('Colaborador')->searchable()->hidden($doColaborador),
                TextColumn::make('natureza')->label('Natureza')->badge(),
                TextColumn::make('valor')->label('Valor')->money('BRL')->alignEnd(),
                TextColumn::make('contaFinanceira.nome')->label('Saiu de')->placeholder('—')
                    ->description(fn (Adiantamento $record): ?string => $record->sessao_caixa_id !== null ? "Sessão #{$record->sessao_caixa_id}" : null),
                TextColumn::make('status')->label('Situação')->badge()
                    ->description(fn (Adiantamento $record): ?string => $record->repasse?->periodo !== null ? 'Repasse '.$record->repasse->periodo->rotulo() : null),
                TextColumn::make('descricao')->label('Descrição')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('registradoPor.name')->label('Registrado por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Situação')->options(StatusAdiantamento::class),
                SelectFilter::make('natureza')->label('Natureza')->options(NaturezaAdiantamento::class),
                SelectFilter::make('colaborador_id')->label('Colaborador')
                    ->options(fn (): array => Colaborador::query()->orderBy('nome')->pluck('nome', 'id')->all())
                    ->searchable()
                    ->hidden($doColaborador),
            ])
            ->recordActions([
                RegistrarAdiantamentoAction::cancelar(),
            ]);
    }
}
