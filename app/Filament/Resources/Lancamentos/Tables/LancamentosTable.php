<?php

namespace App\Filament\Resources\Lancamentos\Tables;

use App\Enums\Comportamento;
use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\CartoesPagamento;
use App\Models\Lancamento;
use App\Models\Maquininha;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\Prestador;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
                self::grupoFavorecido(),
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
            ->filters(self::filtros(), layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormSchema(self::secoesDosFiltros(...))
            ->deferFilters(false)
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
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
     * Favorecido mora em duas colunas — fornecedor (favorecido_id) no Pagar e
     * cliente (cliente_id) no Receber — então a chave do grupo junta as duas
     * com prefixo ("f12"/"c34"). Chave, groupBy do subtotal e escopo por chave
     * precisam produzir exatamente a mesma string.
     */
    protected static function grupoFavorecido(): Group
    {
        return Group::make('favorecido')
            ->label('Favorecido')
            ->getKeyFromRecordUsing(fn (Lancamento $record): ?string => match (true) {
                $record->tipo === TipoLancamento::Pagar && $record->favorecido_id => 'f'.$record->favorecido_id,
                $record->tipo === TipoLancamento::Receber && $record->cliente_id => 'c'.$record->cliente_id,
                default => null,
            })
            ->getTitleFromRecordUsing(fn (Lancamento $record): string => $record->nomeFavorecido ?? 'Sem favorecido')
            ->getDescriptionFromRecordUsing(fn (Lancamento $record): ?string => match (true) {
                $record->nomeFavorecido === null => null,
                $record->tipo === TipoLancamento::Pagar => 'Fornecedor',
                default => 'Cliente',
            })
            ->groupQueryUsing(fn (QueryBuilder $query): QueryBuilder => $query->groupByRaw(
                "CASE WHEN tipo = 'pagar' THEN CONCAT('f', favorecido_id) ELSE CONCAT('c', cliente_id) END"
            ))
            ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query
                ->orderByRaw(
                    'COALESCE('
                    ."(SELECT COALESCE(p.nome_fantasia, p.razao_social, p.nome) FROM prestadores p WHERE p.id = lancamentos.favorecido_id AND lancamentos.tipo = 'pagar'), "
                    ."(SELECT c.cliente_nome FROM clientes c WHERE c.id = lancamentos.cliente_id AND lancamentos.tipo = 'receber')"
                    .') '.($direction === 'desc' ? 'desc' : 'asc')
                )
                // Desempate: homônimos não se intercalam na listagem agrupada.
                ->orderBy('lancamentos.tipo')
                ->orderBy('lancamentos.favorecido_id')
                ->orderBy('lancamentos.cliente_id'))
            ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key): Builder => match (true) {
                blank($key) => $query->where(fn (Builder $q) => $q
                    ->where(fn (Builder $p) => $p->where('tipo', TipoLancamento::Pagar)->whereNull('favorecido_id'))
                    ->orWhere(fn (Builder $r) => $r->where('tipo', TipoLancamento::Receber)->whereNull('cliente_id'))),
                str_starts_with($key, 'f') => $query->where('tipo', TipoLancamento::Pagar)->where('favorecido_id', (int) substr($key, 1)),
                default => $query->where('tipo', TipoLancamento::Receber)->where('cliente_id', (int) substr($key, 1)),
            })
            ->collapsible();
    }

    /** @return array<int, BaseFilter> */
    protected static function filtros(): array
    {
        return [
            Filter::make('codigo')
                ->label('Código')
                ->schema([
                    TextInput::make('codigo')
                        ->label('Código (ID)')
                        ->numeric()
                        ->placeholder('Nº do lançamento'),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['codigo'] ?? null,
                    fn (Builder $q, $id) => $q->whereKey($id),
                ))
                ->indicateUsing(fn (array $data): ?string => filled($data['codigo'] ?? null) ? "Código: {$data['codigo']}" : null),

            Filter::make('texto')
                ->label('Descrição / documento')
                ->schema([
                    TextInput::make('texto')
                        ->label('Descrição ou nº do documento')
                        ->placeholder('Digite parte do texto'),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['texto'] ?? null,
                    fn (Builder $q, $texto) => $q->where(fn (Builder $w) => $w
                        ->where('descricao', 'like', "%{$texto}%")
                        ->orWhere('numero_documento', 'like', "%{$texto}%")),
                ))
                ->indicateUsing(fn (array $data): ?string => filled($data['texto'] ?? null) ? "Texto: {$data['texto']}" : null),

            SelectFilter::make('tipo')
                ->label('Tipo')
                ->options(TipoLancamento::class),

            SelectFilter::make('status')
                ->label('Status')
                ->options(StatusLancamento::class)
                ->multiple(),

            SelectFilter::make('comportamento')
                ->label('Comportamento')
                ->options(Comportamento::class),

            // Título de compra rateado em vários planos tem o cabeçalho nulo —
            // o plano fica nas linhas de lancamento_despesas.
            SelectFilter::make('plano_despesa')
                ->label('Plano de despesa')
                ->options(fn (): array => PlanoDespesa::orderBy('nome')->pluck('nome', 'id')->all())
                ->multiple()
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['values'] ?? [],
                    fn (Builder $q, array $ids) => $q->where(fn (Builder $w) => $w
                        ->whereIn('plano_despesa_id', $ids)
                        ->orWhereHas('despesas', fn (Builder $d) => $d->whereIn('plano_despesa_id', $ids))),
                )),

            SelectFilter::make('plano_receita_id')
                ->label('Plano de receita')
                ->options(fn (): array => PlanoReceita::orderBy('nome')->pluck('nome', 'id')->all())
                ->multiple()
                ->searchable(),

            // options() em vez de relationship(): fornecedor PJ pode ter `nome`
            // nulo, e label nulo quebra o Select — nome_exibicao nunca é nulo.
            SelectFilter::make('favorecido_id')
                ->label('Fornecedor')
                ->options(fn (): array => Prestador::fornecedores()->get()
                    ->mapWithKeys(fn (Prestador $p): array => [$p->id => $p->nome_exibicao])
                    ->sort()
                    ->all())
                ->multiple()
                ->searchable(),

            SelectFilter::make('cliente_id')
                ->label('Cliente')
                ->relationship('cliente', 'cliente_nome')
                ->multiple()
                ->searchable(),

            self::filtroPeriodo('vencimento', 'Vencimento'),
            self::filtroPeriodo('data_pagamento', 'Pagamento'),
            self::filtroPeriodo('competencia', 'Competência'),

            Filter::make('valor')
                ->label('Valor')
                ->schema([
                    Money::make('valor_min')->label('Valor mínimo'),
                    Money::make('valor_max')->label('Valor máximo'),
                ])
                // O Money nasce com "0,00": zero conta como "sem limite".
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when(self::normalizeMoney($data['valor_min'] ?? null), fn (Builder $q, float $minimo) => $q->where('valor', '>=', $minimo))
                    ->when(self::normalizeMoney($data['valor_max'] ?? null), fn (Builder $q, float $maximo) => $q->where('valor', '<=', $maximo)))
                ->indicateUsing(function (array $data): array {
                    $indicadores = [];

                    if ($minimo = self::normalizeMoney($data['valor_min'] ?? null)) {
                        $indicadores[] = 'Valor a partir de R$ '.number_format($minimo, 2, ',', '.');
                    }
                    if ($maximo = self::normalizeMoney($data['valor_max'] ?? null)) {
                        $indicadores[] = 'Valor até R$ '.number_format($maximo, 2, ',', '.');
                    }

                    return $indicadores;
                }),

            SelectFilter::make('origem')
                ->label('Origem')
                ->options([
                    'compra' => 'Compra',
                    'contrato' => 'Contrato',
                    'venda' => 'Venda',
                    'sessao_caixa' => 'Sessão de caixa',
                    'manual' => 'Lançamento manual',
                ])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'compra' => $query->whereNotNull('compra_id'),
                    'contrato' => $query->whereNotNull('contrato_id'),
                    'venda' => $query->whereNotNull('venda_id'),
                    'sessao_caixa' => $query->whereNotNull('sessao_caixa_id'),
                    'manual' => $query->whereNull('compra_id')->whereNull('contrato_id')->whereNull('venda_id')->whereNull('sessao_caixa_id'),
                    default => $query,
                }),

            SelectFilter::make('forma_pagamento')
                ->label('Forma de pagamento')
                ->options(FormaPagamento::class)
                ->multiple()
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['values'] ?? [],
                    fn (Builder $q, array $formas) => $q->whereHas('pagamentos', fn (Builder $p) => $p->whereIn('forma_pagamento', $formas)),
                )),

            Filter::make('vencidos')
                ->label('Somente vencidos')
                ->query(fn (Builder $query): Builder => $query->vencidos())
                ->toggle(),

            Filter::make('vence_7_dias')
                ->label('Vence nos próximos 7 dias')
                ->query(fn (Builder $query): Builder => $query
                    ->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
                    ->whereBetween('vencimento', [today()->toDateString(), today()->addDays(7)->toDateString()]))
                ->toggle(),
        ];
    }

    /**
     * Painel acima da tabela, em seções (mesmo padrão da ProdutosTable). Todo
     * filtro de filtros() TEM que aparecer aqui: filtro fora do form fica com
     * isActive=true por padrão e esvazia a tabela em silêncio.
     *
     * @param  array<string, BaseFilter>  $filters
     * @return array<int, Section>
     */
    protected static function secoesDosFiltros(array $filters): array
    {
        return [
            Section::make('Busca')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    $filters['codigo'],
                    $filters['texto'],
                ]),

            Section::make('Classificação')
                ->icon(Heroicon::OutlinedTag)
                ->collapsed()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    $filters['tipo'],
                    $filters['status'],
                    $filters['comportamento'],
                    $filters['plano_despesa'],
                    $filters['plano_receita_id'],
                    $filters['origem'],
                ]),

            Section::make('Favorecido')
                ->icon(Heroicon::OutlinedUserGroup)
                ->collapsed()
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    $filters['favorecido_id'],
                    $filters['cliente_id'],
                ]),

            Section::make('Datas')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->collapsed()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    $filters['vencimento'],
                    $filters['data_pagamento'],
                    $filters['competencia'],
                ]),

            Section::make('Valores e situação')
                ->icon(Heroicon::OutlinedCurrencyDollar)
                ->collapsed()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    $filters['valor'],
                    $filters['forma_pagamento'],
                    $filters['vencidos'],
                    $filters['vence_7_dias'],
                ]),
        ];
    }

    protected static function filtroPeriodo(string $coluna, string $rotulo): Filter
    {
        return Filter::make($coluna)
            ->label($rotulo)
            ->schema([
                DatePicker::make('de')
                    ->label("{$rotulo} de")
                    ->native(false)
                    ->displayFormat('d/m/Y'),
                DatePicker::make('ate')
                    ->label("{$rotulo} até")
                    ->native(false)
                    ->displayFormat('d/m/Y'),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['de'] ?? null, fn (Builder $q, $valor) => $q->whereDate($coluna, '>=', $valor))
                ->when($data['ate'] ?? null, fn (Builder $q, $valor) => $q->whereDate($coluna, '<=', $valor)))
            ->indicateUsing(function (array $data) use ($rotulo): array {
                $indicadores = [];

                if ($data['de'] ?? null) {
                    $indicadores[] = "{$rotulo} a partir de ".Carbon::parse($data['de'])->format('d/m/Y');
                }
                if ($data['ate'] ?? null) {
                    $indicadores[] = "{$rotulo} até ".Carbon::parse($data['ate'])->format('d/m/Y');
                }

                return $indicadores;
            });
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
                            ->options(FormaPagamento::class)
                            ->live(),
                        TextInput::make('observacoes')
                            ->label('Observações')
                            ->maxLength(255),
                        // Recebimento em cartão/Pix de maquininha: vincula a
                        // maquininha e a bandeira para gravar a taxa e entrar
                        // na conferência (ver TaxaMaquininhaService).
                        Select::make('maquininha_id')
                            ->label('Maquininha')
                            ->options(fn (): array => Maquininha::orderBy('nome')->pluck('nome', 'id')->all())
                            ->default(fn (): ?int => Maquininha::padrao()?->id)
                            ->visible(fn (Get $get, Lancamento $record): bool => $record->tipo === TipoLancamento::Receber
                                && self::forma($get('forma_pagamento'))?->tipoMaquininha() !== null),
                        Select::make('cartao_id')
                            ->label('Bandeira')
                            ->options(fn (): array => CartoesPagamento::all()->mapWithKeys(fn (CartoesPagamento $cartao): array => [
                                $cartao->id => (string) ($cartao->cartao_bandeira ?: $cartao->getRawOriginal('cartao_bandeira')),
                            ])->all())
                            ->visible(fn (Get $get, Lancamento $record): bool => $record->tipo === TipoLancamento::Receber
                                && in_array(self::forma($get('forma_pagamento')), [FormaPagamento::CartaoCredito, FormaPagamento::CartaoDebito], true)),
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
                    $forma = self::forma($item['forma_pagamento'] ?? null);
                    $ehCartao = in_array($forma, [FormaPagamento::CartaoCredito, FormaPagamento::CartaoDebito], true);

                    $record->registrarPagamento(
                        self::normalizeMoney($item['valor'] ?? null),
                        ! empty($item['data_pagamento']) ? Carbon::parse($item['data_pagamento']) : null,
                        $forma,
                        $item['observacoes'] ?? null,
                        cartaoId: $ehCartao && ! empty($item['cartao_id']) ? (int) $item['cartao_id'] : null,
                        maquininhaId: ! empty($item['maquininha_id']) ? (int) $item['maquininha_id'] : null,
                    );
                }

                Notification::make()
                    ->title('Pagamento registrado com sucesso!')
                    ->success()
                    ->send();
            });
    }

    /** O Select entrega o enum, mas durante o preenchimento o estado pode vir como string. */
    private static function forma(mixed $estado): ?FormaPagamento
    {
        return $estado instanceof FormaPagamento ? $estado : FormaPagamento::tryFrom((string) $estado);
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
