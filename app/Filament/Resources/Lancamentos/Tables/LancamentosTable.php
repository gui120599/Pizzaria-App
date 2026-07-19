<?php

namespace App\Filament\Resources\Lancamentos\Tables;

use App\Enums\Comportamento;
use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Lancamento;
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
use Illuminate\Support\Carbon;
use Leandrocfe\FilamentPtbrFormFields\Money;

class LancamentosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager loading dos planos e favorecidos para evitar N+1 nas colunas "Conta" e "Favorecido"
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['planoDespesa', 'planoReceita', 'favorecido', 'cliente'])
                ->withCount('despesas')
                ->withSum('pagamentos', 'valor'))
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
                TextColumn::make('valorPago')
                    ->label('Pago')
                    ->getStateUsing(fn (Lancamento $record): float => $record->valorPago)
                    ->money('BRL')
                    ->toggleable(),
                TextColumn::make('valorRestante')
                    ->label('Restante')
                    ->getStateUsing(fn (Lancamento $record): float => $record->valorRestante)
                    ->money('BRL')
                    ->toggleable(),
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
                self::acaoEstornarPagamento(),
                EditAction::make()
                    ->visible(fn (Lancamento $record): bool => $record->status !== StatusLancamento::Pago),
                DeleteAction::make()
                    ->visible(fn (Lancamento $record): bool => $record->status !== StatusLancamento::Pago),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Registra um pagamento pelo valor informado (default = valor restante, ou seja,
     * quita o título de uma vez). Pra pagamentos parciais adicionais, ou pra ver o
     * histórico completo, usa o repeater "Pagamentos" na tela de edição.
     */
    protected static function acaoMarcarComoPago(): Action
    {
        return Action::make('marcarComoPago')
            ->label('Registrar pagamento')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Lancamento $record): bool => in_array($record->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true))
            ->modalHeading('Registrar pagamento')
            ->modalSubmitActionLabel('Confirmar')
            ->form([
                Money::make('valor')
                    ->label('Valor pago')
                    ->minValue(0.01)
                    // default() só aplica no fill inicial do modal — diferente de
                    // fillForm() na Action, que reaplicaria (e resetaria o que o
                    // usuário digitou) a cada round-trip do Livewire. Precisa vir em
                    // string com 2 casas decimais (formato do cast decimal:2 do Eloquent):
                    // Money::sanitizeState() só sabe interpretar corretamente esse formato
                    // ou o BR ("150,00") — um float cru (150.0) vira 150 CENTAVOS (R$1,50).
                    ->default(fn (Lancamento $record): string => number_format(
                        $record->valorRestante > 0 ? $record->valorRestante : (float) $record->valor,
                        2,
                        '.',
                        ''
                    ))
                    ->required(),
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
                // Select::options(FormaPagamento::class) já entrega o state como
                // instância do enum (Filament casta automaticamente) — nada de
                // ::from() aqui, ou dá TypeError passando enum pra ::from().
                $record->marcarComoPago(
                    ! empty($data['data_pagamento']) ? Carbon::parse($data['data_pagamento']) : null,
                    $data['forma_pagamento'] ?? null,
                    (float) $data['valor'],
                );

                Notification::make()
                    ->title('Pagamento registrado com sucesso!')
                    ->success()
                    ->send();
            });
    }

    /**
     * Apaga todos os pagamentos e volta o título pra Pendente. É o único jeito de
     * corrigir um lançamento totalmente quitado, já que ele fica travado para
     * edição/exclusão direta (ver LancamentoResource::canEdit/canDelete). Pra
     * corrigir um pagamento específico sem apagar os outros, edita direto pelo
     * repeater "Pagamentos" (disponível enquanto o título não estiver 100% pago).
     */
    protected static function acaoEstornarPagamento(): Action
    {
        return Action::make('estornarPagamento')
            ->label('Estornar pagamentos')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Lancamento $record): bool => $record->status === StatusLancamento::Pago)
            ->requiresConfirmation()
            ->modalHeading('Estornar pagamentos')
            ->modalDescription('Todos os pagamentos deste título são apagados e ele volta para Pendente.')
            ->modalSubmitActionLabel('Confirmar estorno')
            ->action(function (Lancamento $record): void {
                $record->estornarPagamento();

                Notification::make()
                    ->title('Pagamento estornado com sucesso!')
                    ->success()
                    ->send();
            });
    }
}
