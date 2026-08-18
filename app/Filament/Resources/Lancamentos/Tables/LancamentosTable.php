<?php

namespace App\Filament\Resources\Lancamentos\Tables;

use App\Enums\Comportamento;
use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Lancamento;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Leandrocfe\FilamentPtbrFormFields\Money;

class LancamentosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager loading dos planos e favorecidos para evitar N+1 nas colunas "Conta" e "Favorecido"
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['planoDespesa', 'planoReceita', 'favorecido', 'cliente', 'compra.prestador'])
                ->withCount('despesas')
                ->withSum('pagamentos', 'valor'))
            ->defaultSort('vencimento', 'asc')
            ->groups([
                Group::make('compra_id')
                    ->label('Compra')
                    ->getTitleFromRecordUsing(fn (Lancamento $record): string => $record->compra
                        ? trim(
                            ($record->compra->compra_numero ? "Compra Nº {$record->compra->compra_numero}" : "Compra #{$record->compra_id}")
                            .($record->compra->prestador?->nome_exibicao ? " — {$record->compra->prestador->nome_exibicao}" : '')
                        )
                        : 'Sem compra vinculada')
                    ->collapsible(),
            ])
            ->columns([
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('descricao')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('parcelaLabel')
                    ->label('Parcela')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),
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
                self::acaoCompensar(),
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
     * Registra um ou mais pagamentos de uma vez (mesmo modelo de repeater usado na
     * confirmação de compra com Prazo de Pagamento): por padrão vem 1 item pré-
     * preenchido com o valor restante (quita o título de uma vez, fluxo mais comum),
     * mas dá pra adicionar mais linhas pra lançar vários pagamentos numa única
     * submissão, sem precisar abrir a tela de edição.
     */
    protected static function acaoMarcarComoPago(): Action
    {
        return Action::make('marcarComoPago')
            ->label('Registrar pagamento')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Lancamento $record): bool => in_array($record->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true)
                && auth()->user()->can('markAsPaid', $record))
            ->modalHeading('Registrar pagamento')
            ->modalDescription('Pode registrar mais de um pagamento de uma vez (ex.: parcial + complemento).')
            ->modalSubmitActionLabel('Confirmar')
            ->form([
                Repeater::make('pagamentos')
                    ->label('')
                    ->schema([
                        DatePicker::make('data_pagamento')
                            ->label('Data')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(now())
                            ->required(),
                        Money::make('valor')
                            ->label('Valor')
                            ->minValue(0.01)
                            ->live(onBlur: true)
                            ->required(),
                        Select::make('forma_pagamento')
                            ->label('Forma')
                            ->options(FormaPagamento::class),
                        TextInput::make('observacoes')
                            ->label('Observações')
                            ->maxLength(255),
                    ])
                    ->live()
                    ->columns(4)
                    ->addActionLabel('Adicionar pagamento')
                    ->reorderable(false)
                    ->defaultItems(1)
                    ->minItems(1)
                    // default() só aplica no fill inicial do modal — diferente de
                    // fillForm() na Action, que reaplicaria (e resetaria o que o
                    // usuário digitou) a cada round-trip do Livewire. Precisa vir em
                    // string com 2 casas decimais (formato do cast decimal:2 do Eloquent):
                    // Money::sanitizeState() só sabe interpretar corretamente esse formato
                    // ou o BR ("150,00") — um float cru (150.0) vira 150 CENTAVOS (R$1,50).
                    ->default(fn (Lancamento $record): array => [[
                        'data_pagamento' => now()->toDateString(),
                        'valor' => number_format(
                            $record->valorRestante > 0 ? $record->valorRestante : (float) $record->valor,
                            2,
                            '.',
                            ''
                        ),
                    ]])
                    ->itemLabel(fn (array $state): ?string => isset($state['valor'])
                        ? 'R$ '.number_format(self::normalizeMoney($state['valor']), 2, ',', '.')
                        : 'Novo pagamento')
                    // A soma dos pagamentos lançados aqui não pode ultrapassar o que
                    // ainda falta pagar/receber no título.
                    ->rule(function (Lancamento $record): Closure {
                        return function (string $attribute, $value, Closure $fail) use ($record): void {
                            $totalPagamentos = collect($value)
                                ->sum(fn (array $item): float => self::normalizeMoney($item['valor'] ?? null));
                            $restante = $record->valorRestante > 0 ? $record->valorRestante : (float) $record->valor;

                            if (round($totalPagamentos, 2) > round($restante, 2) + 0.01) {
                                $fail(
                                    'A soma dos pagamentos (R$ '.number_format($totalPagamentos, 2, ',', '.').
                                    ') não pode ultrapassar o valor restante do título (R$ '.number_format($restante, 2, ',', '.').').'
                                );
                            }
                        };
                    })
                    ->columnSpanFull(),
            ])
            ->action(function (Lancamento $record, array $data): void {
                foreach ($data['pagamentos'] as $item) {
                    // Select::options(FormaPagamento::class) já entrega o state como
                    // instância do enum (Filament casta automaticamente) — nada de
                    // ::from() aqui, ou dá TypeError passando enum pra ::from().
                    $record->registrarPagamento(
                        self::normalizeMoney($item['valor'] ?? null),
                        ! empty($item['data_pagamento']) ? Carbon::parse($item['data_pagamento']) : null,
                        $item['forma_pagamento'] ?? null,
                        $item['observacoes'] ?? null,
                    );
                }

                Notification::make()
                    ->title('Pagamento registrado com sucesso!')
                    ->success()
                    ->send();
            });
    }

    /**
     * Money::make() mantém o valor em estado bruto formatado (ex: "1.234,56") enquanto
     * o form não é salvo. Pra somar/calcular em tempo real, precisa converter pro padrão
     * decimal (ponto), igual o dehydrateCurrency() do próprio campo faz no submit.
     */
    private static function normalizeMoney(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) str_replace(['.', ','], ['', '.'], (string) $value);
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
            ->visible(fn (Lancamento $record): bool => $record->status === StatusLancamento::Pago
                && auth()->user()->can('reversePayment', $record))
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

    /**
     * Compensa um título a pagar (tipo=Pagar) com títulos a receber em aberto do
     * cliente vinculado ao mesmo Prestador (favorecido) — caso de um fornecedor
     * de serviço que também consome no PDV e quer descontar os próprios pedidos
     * do repasse. Registra um pagamento (forma=Compensacao) nos DOIS lançamentos
     * ao mesmo tempo, reaproveitando 100% Lancamento::registrarPagamento() —
     * nenhuma tabela nova, nenhuma aritmética própria.
     */
    protected static function acaoCompensar(): Action
    {
        return Action::make('compensar')
            ->label('Compensar com pedidos do fornecedor')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('info')
            ->visible(fn (Lancamento $record): bool => $record->tipo === TipoLancamento::Pagar
                && in_array($record->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true)
                && $record->favorecido?->cliente_id !== null
                && auth()->user()->can('compensar', $record))
            ->modalHeading('Compensar com pedidos do fornecedor')
            ->modalDescription('Abate do valor restante deste título o valor de títulos a receber em aberto do cliente vinculado a este fornecedor.')
            ->modalSubmitActionLabel('Confirmar compensação')
            ->form(fn (Lancamento $record): array => [
                Repeater::make('compensacoes')
                    ->label('')
                    ->schema([
                        Select::make('lancamento_receber_id')
                            ->label('Título a receber')
                            ->options(fn (): array => self::opcoesRecebiveis($record))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set) use ($record): void {
                                $receber = Lancamento::find($state);
                                if (! $receber) {
                                    return;
                                }

                                $valor = min((float) $receber->valorRestante, (float) $record->valorRestante);
                                $set('valor', number_format($valor, 2, '.', ''));
                            })
                            ->columnSpan(3),
                        Money::make('valor')
                            ->label('Valor')
                            ->minValue(0.01)
                            ->live(onBlur: true)
                            ->required()
                            ->columnSpan(1),
                    ])
                    ->columns(4)
                    ->addActionLabel('Adicionar título')
                    ->reorderable(false)
                    ->defaultItems(1)
                    ->minItems(1)
                    // Mesma trava de acaoMarcarComoPago(): a soma compensada não pode
                    // ultrapassar o que ainda falta pagar neste título.
                    ->rule(function (Lancamento $record): Closure {
                        return function (string $attribute, $value, Closure $fail) use ($record): void {
                            $totalCompensado = collect($value)
                                ->sum(fn (array $item): float => self::normalizeMoney($item['valor'] ?? null));
                            $restante = $record->valorRestante > 0 ? $record->valorRestante : (float) $record->valor;

                            if (round($totalCompensado, 2) > round($restante, 2) + 0.01) {
                                $fail(
                                    'A soma das compensações (R$ '.number_format($totalCompensado, 2, ',', '.').
                                    ') não pode ultrapassar o valor restante deste título (R$ '.number_format($restante, 2, ',', '.').').'
                                );
                            }
                        };
                    })
                    ->columnSpanFull(),
            ])
            ->action(function (Lancamento $record, array $data): void {
                DB::transaction(function () use ($record, $data): void {
                    foreach ($data['compensacoes'] as $item) {
                        $receber = Lancamento::find($item['lancamento_receber_id'] ?? null);
                        $valor = self::normalizeMoney($item['valor'] ?? null);

                        if (! $receber || $valor <= 0) {
                            continue;
                        }

                        $receber->registrarPagamento(
                            $valor,
                            forma: FormaPagamento::Compensacao,
                            observacoes: "Compensado com Lançamento a pagar #{$record->id}",
                        );
                        $record->registrarPagamento(
                            $valor,
                            forma: FormaPagamento::Compensacao,
                            observacoes: "Compensado com Lançamento a receber #{$receber->id} (Venda #{$receber->venda_id})",
                        );
                    }
                });

                Notification::make()
                    ->title('Compensação registrada com sucesso!')
                    ->success()
                    ->send();
            });
    }

    /** @return array<int, string> */
    private static function opcoesRecebiveis(Lancamento $record): array
    {
        $clienteId = $record->favorecido?->cliente_id;
        if (! $clienteId) {
            return [];
        }

        return Lancamento::receber()
            ->pendentes()
            ->where('cliente_id', $clienteId)
            ->get()
            ->mapWithKeys(fn (Lancamento $l): array => [
                $l->id => 'Venda #'.$l->venda_id.' — R$ '.number_format($l->valorRestante, 2, ',', '.').' (vence '.$l->vencimento->format('d/m/Y').')',
            ])
            ->all();
    }
}
