<?php

namespace App\Filament\Pages;

use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Exceptions\FinanceiroException;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Filament\Resources\Compras\CompraResource;
use App\Models\Lancamento;
use App\Models\Prestador;
use App\Services\Compras\FinanceiroCompraService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Leandrocfe\FilamentPtbrFormFields\Money;
use UnitEnum;

/**
 * Parcelas das compras automatizadas que esperam confirmação de pagamento:
 * fornecedor com regra manual (ou sem regra) e baixas automáticas que não
 * deram certo (caixa fechado, conta faltando). Confirma uma a uma (com valor
 * editável para juros/multa) ou em lote, sempre pela baixa existente.
 */
class PagamentosAConfirmar extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $routePath = '/pagamentos-a-confirmar';

    protected static ?string $title = 'Pagamentos a confirmar';

    protected static ?string $navigationLabel = 'Pagamentos a confirmar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.pagamentos-a-confirmar';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('confirmar:pagamento_compra') ?? false;
    }

    /**
     * Títulos em aberto das compras automatizadas que não se baixam sozinhos,
     * mais os "por conciliação" já vencidos há mais dias do que a janela de
     * conciliação (o débito não apareceu no extrato).
     */
    public static function consulta(): Builder
    {
        $limiteConciliacao = today()->subDays((int) config('bancos.conciliacao.dias_depois', 3))->toDateString();
        $regra = fn (array $regras): \Closure => fn (Builder $condicao): Builder => $condicao->whereIn('fcc_regra_baixa', $regras);

        return Lancamento::query()
            ->pagar()
            ->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
            ->whereHas('compra', fn (Builder $compra): Builder => $compra->where('compra_importacao_automatica', true))
            ->where(fn (Builder $titulo): Builder => $titulo
                // Sem parâmetros, regra manual (ou vazia) e baixa na emissão que não deu certo.
                ->whereDoesntHave('compra.prestador.condicaoCompra')
                ->orWhereHas('compra.prestador.condicaoCompra', fn (Builder $condicao): Builder => $condicao->whereNull('fcc_regra_baixa'))
                ->orWhereHas('compra.prestador.condicaoCompra', $regra([RegraBaixaCompra::Manual->value, RegraBaixaCompra::AutomaticaNaEmissao->value]))
                // Por conciliação vencida além da janela: o débito não apareceu no extrato.
                ->orWhere(fn (Builder $vencida): Builder => $vencida
                    ->whereDate('vencimento', '<', $limiteConciliacao)
                    ->whereHas('compra.prestador.condicaoCompra', $regra([RegraBaixaCompra::PorConciliacao->value]))));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::consulta()->with(['compra.prestador.condicaoCompra', 'pagamentos']))
            ->defaultSort('vencimento')
            ->columns([
                TextColumn::make('favorecido')
                    ->label('Fornecedor')
                    ->state(fn (Lancamento $record): string => $record->compra?->prestador?->nome_exibicao ?? '—'),
                TextColumn::make('descricao')->label('Título')->wrap()
                    ->url(fn (Lancamento $record): ?string => $record->compra_id ? CompraResource::getUrl('edit', ['record' => $record->compra_id]) : null),
                TextColumn::make('vencimento')->label('Vencimento')->date('d/m/Y')->sortable()
                    ->color(fn (Lancamento $record): ?string => $record->vencimento?->isPast() && ! $record->vencimento->isToday() ? 'danger' : null),
                TextColumn::make('valor_restante')->label('A pagar')
                    ->state(fn (Lancamento $record): float => (float) $record->valorRestante)
                    ->money('BRL')->alignEnd(),
                TextColumn::make('forma_pagamento')->label('Forma')->badge()->placeholder('—'),
                TextColumn::make('regra')->label('Regra do fornecedor')
                    ->state(fn (Lancamento $record): string => $record->compra?->prestador?->condicaoCompra?->regraBaixa()->getLabel() ?? 'Sem parâmetros')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('proximos_7_dias')
                    ->label('Vencidos ou vencendo em 7 dias')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereDate('vencimento', '<=', today()->addDays(7))),
                SelectFilter::make('favorecido_id')
                    ->label('Fornecedor')
                    ->options(fn (): array => Prestador::fornecedores()->get()->mapWithKeys(fn (Prestador $p): array => [$p->id => $p->nome_exibicao])->all())
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('confirmar')
                    ->label('Confirmar pagamento')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->fillForm(fn (Lancamento $record): array => [
                        ...self::padroes(collect([$record])),
                        'valor' => number_format((float) $record->valorRestante, 2, ',', '.'),
                    ])
                    ->schema([
                        ...self::camposPagamento(),
                        Money::make('valor')
                            ->label('Valor pago')
                            ->helperText('Altere se o pagamento teve juros, multa ou desconto.')
                            ->minValue(0.01)
                            ->required(),
                    ])
                    ->action(function (Lancamento $record, array $data, Action $action): void {
                        $erro = self::confirmar($record, $data, self::numero($data['valor'] ?? null));

                        if ($erro !== null) {
                            Notification::make()->title('Não foi possível confirmar')->body($erro)->danger()->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()->title('Pagamento confirmado')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('confirmarEmLote')
                    ->label('Confirmar pagamento')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->modalDescription('Confirma o valor em aberto de cada parcela selecionada. Para registrar juros ou multa, confirme a parcela sozinha.')
                    ->fillForm(fn (Collection $records): array => self::padroes($records))
                    ->schema(self::camposPagamento())
                    ->action(function (Collection $records, array $data): void {
                        $erros = [];
                        foreach ($records as $titulo) {
                            $erro = self::confirmar($titulo, $data, null);
                            if ($erro !== null) {
                                $erros[] = "{$titulo->descricao}: {$erro}";
                            }
                        }

                        $confirmados = $records->count() - count($erros);
                        Notification::make()
                            ->title("{$confirmados} pagamento(s) confirmado(s)")
                            ->body($erros === [] ? null : implode("\n", $erros))
                            ->{$erros === [] ? 'success' : 'warning'}()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /** @return array<int, mixed> */
    private static function camposPagamento(): array
    {
        return [
            DatePicker::make('data_pagamento')
                ->label('Data do pagamento')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->maxDate(today())
                ->required(),
            ContaFinanceiraSelect::make('conta_financeira_id')
                ->label('Conta de origem')
                ->sugerirForma()
                ->required(),
            ContaFinanceiraSelect::sessaoCaixa(),
            Select::make('forma_pagamento')
                ->label('Forma de pagamento')
                ->options(FormaPagamento::class)
                ->native(false)
                ->required(),
        ];
    }

    /**
     * Data de hoje e, se todas as parcelas forem do mesmo fornecedor, a conta
     * e a forma cadastradas nele.
     *
     * @param  Collection<int, Lancamento>  $titulos
     * @return array<string, mixed>
     */
    private static function padroes(Collection $titulos): array
    {
        $condicoes = $titulos->map(fn (Lancamento $t) => $t->compra?->prestador?->condicaoCompra)->unique(fn ($c) => $c?->id);
        $condicao = $condicoes->count() === 1 ? $condicoes->first() : null;
        $formas = $titulos->map(fn (Lancamento $t) => $t->forma_pagamento?->value)->filter()->unique();

        return [
            'data_pagamento' => today(),
            'conta_financeira_id' => $condicao?->fcc_conta_financeira_id,
            'forma_pagamento' => $condicao?->fcc_forma_pagamento?->value ?? ($formas->count() === 1 ? $formas->first() : null),
            'sessao_caixa_id' => null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    private static function confirmar(Lancamento $titulo, array $data, ?float $valor): ?string
    {
        try {
            app(FinanceiroCompraService::class)->confirmarPagamento(
                $titulo,
                $valor,
                Carbon::parse($data['data_pagamento'])->startOfDay(),
                self::forma($data['forma_pagamento'] ?? null),
                (int) $data['conta_financeira_id'],
                filled($data['sessao_caixa_id'] ?? null) ? (int) $data['sessao_caixa_id'] : null,
                auth()->user(),
            );
        } catch (FinanceiroException $e) {
            return $e->getMessage();
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }

        return null;
    }

    /** O Select com enum devolve a instância; o fillForm, a string. */
    private static function forma(mixed $estado): ?FormaPagamento
    {
        return $estado instanceof FormaPagamento ? $estado : FormaPagamento::tryFrom((string) $estado);
    }

    private static function numero(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return is_numeric($valor) ? (float) $valor : (float) str_replace(['.', ','], ['', '.'], (string) $valor);
    }
}
