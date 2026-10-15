<?php

namespace App\Filament\Resources\Colaboradores\Tables;

use App\Enums\Ponto\VinculoColaborador;
use App\Filament\Resources\Colaboradores\Support\DesligarColaboradorAction;
use App\Models\Colaborador;
use App\Support\DiaOperacional;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ColaboradoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['escalas' => fn ($q) => $q->vigenteEm(today())->with('escala')])
                // Marcações do dia operacional (sem ajustes): ímpar = está trabalhando agora.
                ->withCount(['marcacoes as marcacoes_hoje' => fn ($q) => $q->whereDate('data_referencia', DiaOperacional::de(now())->toDateString())]))
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Colaborador $record): ?string => $record->cargo),
                TextColumn::make('vinculo')
                    ->label('Vínculo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('periodicidade_repasse')
                    ->label('Repasse')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('escala_vigente')
                    ->label('Escala')
                    ->state(fn (Colaborador $record): ?string => $record->escalas->first()?->escala?->nome)
                    ->placeholder('Sem escala'),
                IconColumn::make('trabalhando')
                    ->label('Trabalhando agora')
                    ->state(fn (Colaborador $record): bool => $record->marcacoes_hoje % 2 === 1)
                    ->boolean()
                    ->trueIcon('heroicon-o-play-circle')
                    ->falseIcon('heroicon-o-minus')
                    ->alignCenter(),
                TextColumn::make('data_desligamento')
                    ->label('Situação')
                    ->state(fn (Colaborador $record): string => match (true) {
                        $record->trashed() => 'Excluído',
                        $record->data_desligamento !== null && $record->data_desligamento->lt(today()) => 'Desligado em '.$record->data_desligamento->format('d/m/Y'),
                        default => 'Ativo',
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Ativo' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('vinculo')
                    ->label('Vínculo')
                    ->options(VinculoColaborador::class),
                TernaryFilter::make('ativos')
                    ->label('Situação')
                    ->placeholder('Todos')
                    ->trueLabel('Ativos')
                    ->falseLabel('Desligados')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->ativos(),
                        false: fn (Builder $query): Builder => $query->whereDate('data_desligamento', '<', today()->toDateString()),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    DesligarColaboradorAction::make(),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ]);
    }
}
