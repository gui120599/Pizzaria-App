<?php

namespace App\Filament\Resources\Vendas\Tables;

use App\Models\Caixa;
use App\Models\Cliente;
use App\Models\OpcoesPagamento;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Filtros da tabela de vendas, organizados em seções (mesmo padrão da
 * LancamentosTable). Todo filtro declarado em filtros() precisa entrar em
 * secoes(): fora do filtersFormSchema ele nasce ativo e zera a listagem.
 */
class VendasFiltros
{
    /**
     * @return array<int, Filter|SelectFilter>
     */
    public static function filtros(): array
    {
        return [
            SelectFilter::make('venda_status')
                ->label('Status da venda')
                ->options([
                    'INICIADA' => 'Iniciada',
                    'FINALIZADA' => 'Finalizada',
                    'CANCELADA' => 'Cancelada',
                ]),

            Filter::make('periodo')
                ->label('Finalizada')
                ->schema([
                    ToggleButtons::make('atalho')
                        ->label('Atalho')
                        ->options(['hoje' => 'Hoje', 'ontem' => 'Ontem', '7dias' => 'Últimos 7 dias', 'mes' => 'Este mês'])
                        ->inline()
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
                    DatePicker::make('de')->label('Finalizada de')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('ate')->label('Finalizada até')->native(false)->displayFormat('d/m/Y'),
                ])
                ->columns(3)
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['de'] ?? null, fn (Builder $q, $valor) => $q->whereDate('venda_datahora_finalizada', '>=', $valor))
                    ->when($data['ate'] ?? null, fn (Builder $q, $valor) => $q->whereDate('venda_datahora_finalizada', '<=', $valor)))
                ->indicateUsing(function (array $data): array {
                    $indicadores = [];

                    if ($data['de'] ?? null) {
                        $indicadores[] = 'Finalizada a partir de '.Carbon::parse($data['de'])->format('d/m/Y');
                    }
                    if ($data['ate'] ?? null) {
                        $indicadores[] = 'Finalizada até '.Carbon::parse($data['ate'])->format('d/m/Y');
                    }

                    return $indicadores;
                }),

            SelectFilter::make('caixa_id')
                ->label('Caixa')
                ->options(fn (): array => Caixa::query()->orderBy('caixa_nome')->pluck('caixa_nome', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->doCaixa((int) $data['value']) : $query),

            Filter::make('sessao_caixa')
                ->label('Sessão de caixa')
                ->schema([
                    Select::make('sessao_id')
                        ->label('Sessão de caixa')
                        ->options(fn (): array => SessaoCaixa::query()
                            ->with('caixa')
                            ->latest('id')
                            ->limit(60)
                            ->get()
                            ->mapWithKeys(fn (SessaoCaixa $sessao): array => [
                                $sessao->id => "#{$sessao->id} — ".($sessao->caixa?->caixa_nome ?? 'Caixa').' — '.$sessao->sessaocaixa_data_hora_abertura?->format('d/m/Y H:i'),
                            ])
                            ->all())
                        ->searchable(),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['sessao_id'] ?? null, fn (Builder $q, $id) => $q->where('venda_sessao_caixa_id', $id)))
                ->indicateUsing(fn (array $data): array => filled($data['sessao_id'] ?? null) ? ['Sessão de caixa #'.$data['sessao_id']] : []),

            SelectFilter::make('operador_id')
                ->label('Operador do caixa')
                ->options(fn (): array => User::query()
                    ->whereIn('id', SessaoCaixa::query()->select('sessaocaixa_user_id'))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->doOperador((int) $data['value']) : $query),

            Filter::make('cliente')
                ->label('Cliente')
                ->schema([
                    Select::make('cliente_id')
                        ->label('Cliente')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Cliente::query()
                            ->where(fn ($query) => $query->where('cliente_nome', 'like', "%{$search}%")->orWhere('cliente_cpf', 'like', "%{$search}%"))
                            ->orderBy('cliente_nome')
                            ->limit(50)
                            ->pluck('cliente_nome', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Cliente::find($value)?->cliente_nome),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['cliente_id'] ?? null, fn (Builder $q, $id) => $q->where('venda_cliente_id', $id)))
                ->indicateUsing(fn (array $data): array => filled($data['cliente_id'] ?? null) ? ['Cliente: '.(Cliente::find($data['cliente_id'])?->cliente_nome ?? '#'.$data['cliente_id'])] : []),

            Filter::make('sem_cliente')
                ->label('Sem cliente')
                ->query(fn (Builder $query): Builder => $query->whereNull('venda_cliente_id'))
                ->toggle(),

            SelectFilter::make('forma_pagamento')
                ->label('Forma de pagamento')
                ->options(fn (): array => OpcoesPagamento::query()->withTrashed()->orderBy('opcaopag_nome')->pluck('opcaopag_nome', 'id')->all())
                ->multiple()
                ->query(fn (Builder $query, array $data): Builder => ($data['values'] ?? []) !== [] ? $query->comFormaPagamento($data['values']) : $query),

            SelectFilter::make('situacao_nfe')
                ->label('Situação da NFC-e')
                ->options(Venda::SITUACOES_NFE)
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->situacaoNfe($data['value']) : $query),

            Filter::make('valor')
                ->label('Valor total')
                ->schema([
                    Money::make('valor_min')->label('Valor mínimo'),
                    Money::make('valor_max')->label('Valor máximo'),
                ])
                // O Money nasce com "0,00": zero conta como "sem limite".
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when(self::numero($data['valor_min'] ?? null), fn (Builder $q, float $minimo) => $q->where('venda_valor_total', '>=', $minimo))
                    ->when(self::numero($data['valor_max'] ?? null), fn (Builder $q, float $maximo) => $q->where('venda_valor_total', '<=', $maximo)))
                ->indicateUsing(function (array $data): array {
                    $indicadores = [];

                    if ($minimo = self::numero($data['valor_min'] ?? null)) {
                        $indicadores[] = 'Total a partir de R$ '.number_format($minimo, 2, ',', '.');
                    }
                    if ($maximo = self::numero($data['valor_max'] ?? null)) {
                        $indicadores[] = 'Total até R$ '.number_format($maximo, 2, ',', '.');
                    }

                    return $indicadores;
                }),

            Filter::make('com_fiado')->label('A prazo (fiado)')->query(fn (Builder $query): Builder => $query->comFiado())->toggle(),
            Filter::make('com_desconto')->label('Com desconto')->query(fn (Builder $query): Builder => $query->comDesconto())->toggle(),
            Filter::make('com_cortesia')->label('Com cortesia')->query(fn (Builder $query): Builder => $query->comCortesia())->toggle(),
            Filter::make('com_taxa_servico')->label('Com taxa de serviço')->query(fn (Builder $query): Builder => $query->comTaxaServico())->toggle(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, Section>
     */
    public static function secoes(array $filters): array
    {
        return [
            Section::make('Período e caixa')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    $filters['periodo']->columnSpanFull(),
                    $filters['caixa_id'],
                    $filters['sessao_caixa'],
                    $filters['operador_id'],
                ]),

            Section::make('Cliente e pagamento')
                ->icon(Heroicon::OutlinedUserGroup)
                ->collapsed()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    $filters['cliente'],
                    $filters['sem_cliente'],
                    $filters['forma_pagamento'],
                ]),

            Section::make('NFC-e e status')
                ->icon(Heroicon::OutlinedDocumentText)
                ->collapsed()
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    $filters['situacao_nfe'],
                    $filters['venda_status'],
                ]),

            Section::make('Valores e características')
                ->icon(Heroicon::OutlinedCurrencyDollar)
                ->collapsed()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    $filters['valor'],
                    $filters['com_fiado'],
                    $filters['com_desconto'],
                    $filters['com_cortesia'],
                    $filters['com_taxa_servico'],
                ]),
        ];
    }

    private static function numero(mixed $valor): float
    {
        if ($valor === null || $valor === '') {
            return 0.0;
        }

        return is_numeric($valor) ? (float) $valor : (float) str_replace(['.', ','], ['', '.'], (string) $valor);
    }
}
