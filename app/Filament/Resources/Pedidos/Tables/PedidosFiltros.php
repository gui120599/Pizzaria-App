<?php

namespace App\Filament\Resources\Pedidos\Tables;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\SituacaoPagamentoPedidoEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\TipoAtendimentoEnum;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filtros da lista de pedidos, em seções (mesmo padrão de VendasFiltros). Todo
 * filtro declarado em filtros() precisa entrar em secoes(): fora do
 * filtersFormSchema ele nasce ativo e derruba as ações em lote.
 *
 * A forma de pagamento filtrada é a da VENDA (o que de fato foi recebido), e
 * nunca a "combinada" no atendimento, que é informativa e existe em poucos
 * pedidos.
 */
class PedidosFiltros
{
    /**
     * @return array<int, Filter|SelectFilter>
     */
    public static function filtros(): array
    {
        return [
            Filter::make('periodo')
                ->label('Abertura')
                ->schema([
                    ToggleButtons::make('atalho')
                        ->label('Atalho')
                        ->options(['hoje' => 'Hoje', 'ontem' => 'Ontem', '7dias' => 'Últimos 7 dias', 'mes' => 'Este mês'])
                        ->inline()
                        ->columnSpanFull()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            [$de, $ate] = match ($state) {
                                'hoje' => [today(), today()],
                                'ontem' => [today()->subDay(), today()->subDay()],
                                '7dias' => [today()->subDays(6), today()],
                                'mes' => [today()->startOfMonth(), today()],
                                default => [null, null],
                            };
                            $set('de', $de?->toDateString());
                            $set('ate', $ate?->toDateString());
                        }),
                    DatePicker::make('de')->label('Aberto de')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('ate')->label('Aberto até')->native(false)->displayFormat('d/m/Y'),
                ])
                ->columns(2)
                ->query(fn (Builder $query, array $data): Builder => $query->abertoEntre(
                    filled($data['de'] ?? null) ? Carbon::parse($data['de']) : null,
                    filled($data['ate'] ?? null) ? Carbon::parse($data['ate']) : null,
                ))
                ->indicateUsing(function (array $data): array {
                    $indicadores = [];

                    if ($data['de'] ?? null) {
                        $indicadores[] = 'Aberto a partir de '.Carbon::parse($data['de'])->format('d/m/Y');
                    }
                    if ($data['ate'] ?? null) {
                        $indicadores[] = 'Aberto até '.Carbon::parse($data['ate'])->format('d/m/Y');
                    }

                    return $indicadores;
                }),

            SelectFilter::make('status')
                ->label('Status')
                ->options(StatusPedidoEnum::paraSelect())
                ->multiple()
                ->query(fn (Builder $query, array $data): Builder => ($data['values'] ?? []) !== []
                    ? $query->whereIn('pedidos.pedido_status', $data['values'])
                    : $query),

            SelectFilter::make('tipo_atendimento')
                ->label('Tipo de atendimento')
                ->options(collect(TipoAtendimentoEnum::cases())->mapWithKeys(fn (TipoAtendimentoEnum $tipo): array => [$tipo->value => $tipo->label()])->all())
                ->multiple()
                ->query(fn (Builder $query, array $data): Builder => ($data['values'] ?? []) !== []
                    ? $query->tipoAtendimento(array_map(TipoAtendimentoEnum::from(...), $data['values']))
                    : $query),

            SelectFilter::make('origem')
                ->label('Origem')
                ->options(collect(PedidoOrigemEnum::cases())->mapWithKeys(fn (PedidoOrigemEnum $origem): array => [$origem->value => $origem->label()])->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('pedidos.pedido_origem', $data['value']) : $query),

            SelectFilter::make('opcao_entrega')
                ->label('Opção de entrega')
                ->options(fn (): array => OpcoesEntregas::query()->withTrashed()->orderBy('opcaoentrega_nome')->pluck('opcaoentrega_nome', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('pedidos.pedido_opcaoentrega_id', $data['value']) : $query),

            SelectFilter::make('atendente')
                ->label('Atendente')
                ->options(fn (): array => self::usuariosDoPedido('pedido_usuario_garcom_id'))
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('pedidos.pedido_usuario_garcom_id', $data['value']) : $query),

            SelectFilter::make('entregador')
                ->label('Entregador')
                ->options(fn (): array => self::usuariosDoPedido('pedido_usuario_entrega_id'))
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('pedidos.pedido_usuario_entrega_id', $data['value']) : $query),

            SelectFilter::make('mesa')
                ->label('Mesa')
                ->options(fn (): array => Mesa::query()->orderBy('mesa_nome')->pluck('mesa_nome', 'id')->all())
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->whereHas('sessaoMesa', fn (Builder $sessao) => $sessao->where('sessao_mesa_mesa_id', $data['value']))
                    : $query),

            SelectFilter::make('situacao_pagamento')
                ->label('Situação do pagamento')
                ->options(SituacaoPagamentoPedidoEnum::paraSelect())
                ->query(fn (Builder $query, array $data): Builder => self::situacaoPagamento($query, $data['value'] ?? null)),

            SelectFilter::make('forma_pagamento')
                ->label('Forma de pagamento (da venda)')
                ->options(fn (): array => OpcoesPagamento::query()->withTrashed()->orderBy('opcaopag_nome')->pluck('opcaopag_nome', 'id')->all())
                ->multiple()
                ->query(fn (Builder $query, array $data): Builder => self::comFormaPagamento($query, $data['values'] ?? [])),

            SelectFilter::make('motivo_cancelamento')
                ->label('Motivo do cancelamento')
                ->options(MotivoCancelamentoEnum::paraSelect())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('pedidos.pedido_motivo_cancelamento', $data['value']) : $query),

            Filter::make('com_desconto')
                ->label('Com desconto')
                ->query(fn (Builder $query): Builder => $query->where('pedidos.pedido_valor_desconto', '>', 0))
                ->toggle(),

            Filter::make('com_cortesia')
                ->label('Com cortesia')
                ->query(fn (Builder $query): Builder => $query->whereHas('item_pedido_pedido_id', fn (Builder $item) => $item
                    ->where('item_pedido_status', 'INSERIDO')
                    ->where('item_pedido_cortesia', true)))
                ->toggle(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, Section>
     */
    public static function secoes(array $filters): array
    {
        return [
            Section::make('Período e situação')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    $filters['periodo']->columnSpanFull(),
                    $filters['status'],
                    $filters['tipo_atendimento'],
                    $filters['situacao_pagamento'],
                ]),

            Section::make('Atendimento')
                ->icon(Heroicon::OutlinedUserGroup)
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    $filters['origem'],
                    $filters['opcao_entrega'],
                    $filters['mesa'],
                    $filters['atendente'],
                    $filters['entregador'],
                ]),

            Section::make('Pagamento e exceções')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    $filters['forma_pagamento'],
                    $filters['motivo_cancelamento'],
                    $filters['com_desconto'],
                    $filters['com_cortesia'],
                ]),
        ];
    }

    /**
     * Espelha Pedido::situacaoPagamento() (a coluna "Pagamento"), em SQL.
     *
     * @return Builder<Pedido>
     */
    private static function situacaoPagamento(Builder $query, ?string $situacao): Builder
    {
        $finalizado = StatusPedidoEnum::FINALIZADO->value;

        return match ($situacao) {
            'pago' => $query->where(fn (Builder $q) => $q
                ->whereNotNull('pedidos.pedido_venda_id')
                ->orWhere('pedidos.pedido_status', $finalizado)),
            'parcial' => $query
                ->whereNull('pedidos.pedido_venda_id')
                ->whereNotIn('pedidos.pedido_status', [$finalizado, StatusPedidoEnum::CANCELADO->value, StatusPedidoEnum::INICIADO->value])
                ->pagos(),
            'a_receber' => $query
                ->semPagamento()
                ->whereNotIn('pedidos.pedido_status', [$finalizado, StatusPedidoEnum::CANCELADO->value, StatusPedidoEnum::INICIADO->value]),
            default => $query,
        };
    }

    /**
     * Forma de pagamento da venda do pedido: a venda vinculada ou a de algum
     * item (conta dividida paga em vendas separadas).
     *
     * @param  array<int, int|string>  $opcaoIds
     * @return Builder<Pedido>
     */
    private static function comFormaPagamento(Builder $query, array $opcaoIds): Builder
    {
        if ($opcaoIds === []) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereHas('vendas', fn (Builder $venda) => $venda->comFormaPagamento($opcaoIds))
            ->orWhereHas('item_pedido_pedido_id', fn (Builder $item) => $item
                ->whereHas('venda', fn (Builder $venda) => $venda->comFormaPagamento($opcaoIds))));
    }

    /**
     * Usuários que aparecem em alguma coluna de usuário do pedido (só quem
     * tem pedido entra no seletor).
     *
     * @return array<int, string>
     */
    private static function usuariosDoPedido(string $coluna): array
    {
        return User::query()
            ->whereIn('id', Pedido::query()->withTrashed()->whereNotNull($coluna)->select($coluna))
            ->orderBy('name_first')
            ->pluck('name_first', 'id')
            ->all();
    }
}
