<?php

namespace App\Filament\Resources\ChecklistModelos\Tables;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Filament\Resources\ChecklistModelos\Support\DuplicarChecklistModeloAction;
use App\Filament\Resources\ChecklistModelos\Support\PreVisualizarChecklistModeloAction;
use App\Models\ChecklistModelo;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChecklistModelosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('roles')->withCount('itens'))
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('setor')
                    ->label('Setor')
                    ->badge()
                    ->sortable(),
                TextColumn::make('painel')
                    ->label('Painel')
                    ->badge()
                    ->color('gray')
                    ->state(fn (ChecklistModelo $record): string => $record->painelDescricao()),
                TextColumn::make('dias_semana')
                    ->label('Dias')
                    ->state(fn (ChecklistModelo $record): string => $record->diasSemanaDescricao()),
                TextColumn::make('janela_inicio')
                    ->label('Janela')
                    ->state(fn (ChecklistModelo $record): ?string => $record->janelaDescricao())
                    ->placeholder('—'),
                TextColumn::make('itens_count')
                    ->label('Itens')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Papéis')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Só gestão'),
                ToggleColumn::make('ativo')
                    ->label('Ativo')
                    ->disabled(fn (ChecklistModelo $record): bool => ! (auth()->user()?->can('update', $record) ?? false)),
                TextColumn::make('versao')
                    ->label('Versão')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(ChecklistTipo::class),
                SelectFilter::make('setor')
                    ->label('Setor')
                    ->options(ChecklistSetor::class),
                SelectFilter::make('painel')
                    ->label('Painel')
                    ->options(ChecklistModelo::opcoesDePainel())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'])
                        ? $query->paraPainel($data['value'])
                        : $query),
                TernaryFilter::make('ativo')
                    ->label('Ativo'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    PreVisualizarChecklistModeloAction::make(),
                    DuplicarChecklistModeloAction::make(),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
