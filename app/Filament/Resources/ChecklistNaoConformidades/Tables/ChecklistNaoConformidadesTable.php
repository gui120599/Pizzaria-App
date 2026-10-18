<?php

namespace App\Filament\Resources\ChecklistNaoConformidades\Tables;

use App\Enums\Checklist\NaoConformidadeStatus;
use App\Models\ChecklistNaoConformidade;
use App\Services\Checklist\ChecklistNaoConformidadeService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::cancelarSelecionadas(),
                ]),
            ]);
    }

    /**
     * Cancela em massa, com motivo. A bulk action do Filament não passa pela
     * policy registro a registro, então a autorização é explícita: só NC
     * pendente e que o usuário pode atualizar; o resto é contado como ignorado.
     */
    private static function cancelarSelecionadas(): BulkAction
    {
        return BulkAction::make('cancelar')
            ->label('Cancelar selecionadas')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancelar não conformidades')
            ->modalDescription('Use para falsos positivos. O motivo fica registrado em cada não conformidade.')
            ->schema([
                Textarea::make('observacao_resolucao')
                    ->label('Motivo')
                    ->required()
                    ->maxLength(1000),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $usuario = Auth::user();
                $servico = app(ChecklistNaoConformidadeService::class);
                $canceladas = 0;
                $ignoradas = 0;

                foreach ($records as $naoConformidade) {
                    if (! in_array($naoConformidade->status, NaoConformidadeStatus::pendentes(), true) || $usuario->cannot('update', $naoConformidade)) {
                        $ignoradas++;

                        continue;
                    }

                    $servico->atualizar($naoConformidade, [
                        'status' => NaoConformidadeStatus::Cancelada,
                        'observacao_resolucao' => $data['observacao_resolucao'],
                    ], $usuario);
                    $canceladas++;
                }

                Notification::make()
                    ->title("{$canceladas} ".($canceladas === 1 ? 'cancelada' : 'canceladas').", {$ignoradas} ".($ignoradas === 1 ? 'ignorada' : 'ignoradas'))
                    ->color($canceladas > 0 ? 'success' : 'warning')
                    ->send();
            });
    }
}
