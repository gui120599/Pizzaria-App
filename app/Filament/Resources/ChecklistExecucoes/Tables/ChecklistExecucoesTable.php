<?php

namespace App\Filament\Resources\ChecklistExecucoes\Tables;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExecucaoStatus;
use App\Filament\Resources\ChecklistExecucoes\Support\AprovarExecucaoAction;
use App\Filament\Resources\ChecklistExecucoes\Support\ReprovarExecucaoAction;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ChecklistExecucoesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['iniciadoPor', 'concluidoPor']))
            ->defaultSort('data_referencia', 'desc')
            ->columns([
                TextColumn::make('data_referencia')
                    ->label('Dia')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('modelo_nome')
                    ->label('Checklist')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('setor')
                    ->label('Setor')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('iniciadoPor.name')
                    ->label('Iniciado por')
                    ->placeholder('—'),
                TextColumn::make('concluido_em')
                    ->label('Concluído em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('percentual_conformidade')
                    ->label('Conformidade')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : number_format((float) $state, 0, ',', '.').'%')
                    ->color(fn (?string $state): string => match (true) {
                        $state === null => 'gray',
                        (float) $state >= 95 => 'success',
                        (float) $state >= 80 => 'warning',
                        default => 'danger',
                    })
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('itens_nao_conformes')
                    ->label('Não conformes')
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('periodo')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('de')
                            ->label('De')
                            ->default(fn (): Carbon => today()->subDays(6)),
                        DatePicker::make('ate')
                            ->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['de'] ?? null, fn (Builder $q, string $de): Builder => $q->whereDate('data_referencia', '>=', $de))
                        ->when($data['ate'] ?? null, fn (Builder $q, string $ate): Builder => $q->whereDate('data_referencia', '<=', $ate)))
                    ->indicateUsing(function (array $data): array {
                        $indicadores = [];

                        if ($data['de'] ?? null) {
                            $indicadores[] = 'De '.Carbon::parse($data['de'])->format('d/m/Y');
                        }

                        if ($data['ate'] ?? null) {
                            $indicadores[] = 'Até '.Carbon::parse($data['ate'])->format('d/m/Y');
                        }

                        return $indicadores;
                    }),
                SelectFilter::make('checklist_modelo_id')
                    ->label('Modelo')
                    ->relationship('modelo', 'nome')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(ChecklistTipo::class),
                SelectFilter::make('setor')
                    ->label('Setor')
                    ->options(ChecklistSetor::class),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ExecucaoStatus::class)
                    ->multiple(),
                SelectFilter::make('iniciado_por')
                    ->label('Iniciado por')
                    ->relationship('iniciadoPor', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    AprovarExecucaoAction::make(),
                    ReprovarExecucaoAction::make(),
                ]),
            ]);
    }
}
