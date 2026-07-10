<?php

namespace App\Filament\Resources\Lancamentos\Tables;

use App\Enums\Comportamento;
use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Lancamento;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LancamentosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager loading dos planos e favorecidos para evitar N+1 nas colunas "Conta" e "Favorecido"
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['planoDespesa', 'planoReceita', 'favorecido', 'cliente'])
                ->withCount('despesas'))
            ->defaultSort('vencimento', 'asc')
            ->columns([
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('descricao')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('conta')
                    ->label('Conta')
                    // Título rateado em vários planos (gerado por compra) mostra "Rateio (N)".
                    ->getStateUsing(fn (Lancamento $record): ?string => $record->plano?->nome
                        ?? (($record->despesas_count ?? 0) > 1 ? "Rateio ({$record->despesas_count} planos)" : null))
                    ->placeholder('—'),
                TextColumn::make('comportamento')
                    ->label('Comportamento')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('nomeFavorecido')
                    ->label('Favorecido')
                    ->getStateUsing(fn (Lancamento $record): ?string => $record->nomeFavorecido)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make()->money('BRL')->label('Total')),
                TextColumn::make('vencimento')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable()
                    // Destaque visual para lançamentos vencidos (estado derivado)
                    ->color(fn (Lancamento $record): ?string => $record->estaVencido ? 'danger' : null)
                    ->weight(fn (Lancamento $record): ?FontWeight => $record->estaVencido ? FontWeight::Bold : null)
                    ->icon(fn (Lancamento $record) => $record->estaVencido ? Heroicon::OutlinedExclamationTriangle : null),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('data_pagamento')
                    ->label('Pagamento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(TipoLancamento::class),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StatusLancamento::class),
                SelectFilter::make('comportamento')
                    ->label('Comportamento')
                    ->options(Comportamento::class),
                Filter::make('vencimento')
                    ->label('Vencimento')
                    ->form([
                        DatePicker::make('de')
                            ->label('De')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('ate')
                            ->label('Até')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['de'] ?? null, fn (Builder $q, $valor) => $q->whereDate('vencimento', '>=', $valor))
                            ->when($data['ate'] ?? null, fn (Builder $q, $valor) => $q->whereDate('vencimento', '<=', $valor));
                    }),
            ])
            ->recordActions([
                self::acaoMarcarComoPago(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Ação de baixa: só aparece em lançamentos pendentes. */
    protected static function acaoMarcarComoPago(): Action
    {
        return Action::make('marcarComoPago')
            ->label('Dar baixa')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Lancamento $record): bool => $record->status === StatusLancamento::Pendente)
            ->modalHeading('Marcar como pago / recebido')
            ->modalSubmitActionLabel('Confirmar baixa')
            ->form([
                DatePicker::make('data_pagamento')
                    ->label('Data do pagamento / recebimento')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(now())
                    ->required(),
                Select::make('forma_pagamento')
                    ->label('Forma de pagamento')
                    ->options(FormaPagamento::class),
            ])
            ->action(function (Lancamento $record, array $data): void {
                $record->marcarComoPago(
                    ! empty($data['data_pagamento']) ? Carbon::parse($data['data_pagamento']) : null,
                    ! empty($data['forma_pagamento']) ? FormaPagamento::from($data['forma_pagamento']) : null,
                );

                Notification::make()
                    ->title('Baixa registrada com sucesso!')
                    ->success()
                    ->send();
            });
    }
}
