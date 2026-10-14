<?php

namespace App\Filament\Resources\SetoresProducao\Tables;

use App\Enums\Cozinha\StatusItemProducao;
use App\Models\DispositivoCozinha;
use App\Models\SetorProducao;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SetoresProducaoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount([
                    'rotas',
                    'itens as fila_count' => fn (Builder $q) => $q->whereIn('status', StatusItemProducao::valoresAtivosNaEstacao()),
                ]))
            ->reorderable('ordem')
            ->defaultSort('ordem')
            ->columns([
                ColorColumn::make('cor')
                    ->label(''),
                TextColumn::make('nome')
                    ->label('Nome')
                    ->icon(fn (SetorProducao $record): ?string => $record->icone)
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('tempos')
                    ->label('Alerta / alvo')
                    ->state(fn (SetorProducao $record): string => "{$record->tempo_alerta_min} / {$record->tempo_alvo_min} min"),
                TextColumn::make('rotas_count')
                    ->label('Rotas')
                    ->tooltip('Produtos e categorias que passam por este setor')
                    ->alignCenter(),
                TextColumn::make('fila_count')
                    ->label('Na fila agora')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->alignCenter(),
                TextColumn::make('tablets')
                    ->label('Tablets')
                    ->state(fn (SetorProducao $record): int => DispositivoCozinha::ativos()->doSetor($record->id)->count())
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('exige_operador')
                    ->label('Operador')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('ativo')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                self::excluirAction(),
                RestoreAction::make(),
            ]);
    }

    /**
     * Setor com rota apontando para ele ou com item ainda na estação não pode
     * sair: o roteador cairia no setor padrão sem aviso e a fila ficaria órfã.
     */
    public static function excluirAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (DeleteAction $action, SetorProducao $record): void {
                $rotas = $record->rotas()->count();
                $ativos = $record->itensAtivos()->count();

                if ($rotas === 0 && $ativos === 0) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->title('Este setor ainda está em uso')
                    ->body(collect([
                        $rotas > 0 ? "{$rotas} rota(s) de produto/categoria apontam para ele" : null,
                        $ativos > 0 ? "{$ativos} item(ns) estão na fila ou em preparo" : null,
                    ])->filter()->implode(' e ').'. Mova as rotas ou desative o setor.')
                    ->persistent()
                    ->send();

                $action->halt();
            });
    }
}
