<?php

namespace App\Filament\Resources\Compras\Support;

use App\Enums\FormaPagamento;
use App\Filament\Resources\PrazosPagamento\Schemas\PrazoPagamentoForm;
use App\Models\Compra;
use App\Models\PrazoPagamento;
use App\Models\PrazoPagamentoParcela;
use App\Services\CompraService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Ação de confirmar compra, reutilizada na tabela e na página de edição.
 *
 * Ao confirmar, opcionalmente gera a(s) conta(s) a pagar (uma por parcela do
 * Repeater), tudo na mesma transação: estoque + títulos nascem juntos ou nada
 * acontece. Um Prazo de Pagamento cadastrado (ex.: "30/60/90") pode ser usado
 * para pré-preencher as parcelas a partir da Data-base, mas o Repeater
 * continua livremente editável antes de confirmar.
 */
class ConfirmarCompraAction
{
    public static function make(string $name = 'confirmar'): Action
    {
        return Action::make($name)
            ->label('Confirmar compra')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Compra $record): bool => $record->isRascunho() && auth()->user()->can('confirm', $record))
            ->modalHeading('Confirmar compra')
            ->modalDescription('Gera as entradas de estoque e recalcula o custo médio. Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Confirmar')
            ->modalWidth('2xl')
            // O $record injetado nos campos do schema pode estar desatualizado em memória
            // (ex.: usuário editou itens na aba "Itens" — um Livewire component separado —
            // e o total foi recalculado no banco, mas a página de edição não recarregou o
            // registro). Sem isso, compra_valor_total fica 0 aqui e as parcelas do prazo
            // de pagamento calculam tudo zerado.
            ->mountUsing(function (Compra $record, ?Schema $schema): void {
                $record->refresh();
                $schema?->fill();
            })
            ->schema([
                Toggle::make('gerar_conta_pagar')
                    ->label('Gerar conta a pagar')
                    ->helperText('Cria um título a pagar por parcela para o fornecedor, rateado pelos planos de despesa dos produtos.')
                    ->default(true)
                    ->live(),
                DatePicker::make('data_base')
                    ->label('Data-base')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->helperText('Usada para calcular os vencimentos das parcelas ao aplicar um Prazo de Pagamento (data-base + dias).')
                    ->default(fn (Compra $record) => $record->compra_data_entrada ?? now())
                    ->required(fn (Get $get): bool => (bool) $get('gerar_conta_pagar'))
                    ->visible(fn (Get $get): bool => (bool) $get('gerar_conta_pagar')),
                Select::make('prazo_pagamento_id')
                    ->label('Prazo de pagamento')
                    ->options(fn (): array => PrazoPagamento::query()
                        ->ativos()
                        ->orderBy('prazo_pagamento_nome')
                        ->pluck('prazo_pagamento_nome', 'id')
                        ->all())
                    ->searchable()
                    ->live()
                    ->helperText('Opcional — calcula os vencimentos e valores das parcelas automaticamente a partir da Data-base. Cada linha continua editável.')
                    ->visible(fn (Get $get): bool => (bool) $get('gerar_conta_pagar'))
                    ->createOptionForm(PrazoPagamentoForm::camposRapidos())
                    ->createOptionUsing(function (array $data): int {
                        PrazoPagamentoForm::validarSomaPercentuais($data['parcelas'] ?? [], 'parcelas');

                        $prazo = PrazoPagamento::create([
                            'prazo_pagamento_nome' => $data['prazo_pagamento_nome'],
                            'prazo_pagamento_ativo' => true,
                        ]);

                        collect($data['parcelas'] ?? [])
                            ->values()
                            ->each(fn (array $parcela, int $indice) => $prazo->parcelas()->create([
                                'parcela_numero' => $indice + 1,
                                'parcela_dias' => $parcela['parcela_dias'],
                                'parcela_percentual' => $parcela['parcela_percentual'],
                            ]));

                        return $prazo->id;
                    })
                    ->afterStateUpdated(function (?int $state, Get $get, Set $set, Compra $record): void {
                        if ($state === null) {
                            return;
                        }

                        $prazo = PrazoPagamento::with('parcelas')->find($state);
                        if (! $prazo || $prazo->parcelas->isEmpty()) {
                            return;
                        }

                        $dataBase = self::parseData($get('data_base')) ?? $record->compra_data_entrada ?? now();
                        $valorTotal = (float) $record->compra_valor_total;

                        $set('parcelas', self::calcularParcelas($prazo->parcelas, $dataBase, $valorTotal));
                    }),
                Repeater::make('parcelas')
                    ->label('Parcelas')
                    // Força o Livewire a destruir e remontar todo o Repeater (inclusive o
                    // estado JS de máscara de moeda de cada input) sempre que o prazo
                    // selecionado mudar — sem isso, os inputs de valor recém-recalculados
                    // via $set() no afterStateUpdated do Select acima podem continuar
                    // mostrando o valor anterior/zerado na tela mesmo com o estado correto
                    // salvo no servidor.
                    ->key(fn (Get $get): string => 'parcelas-'.($get('prazo_pagamento_id') ?? 'manual'))
                    ->schema([
                        DatePicker::make('vencimento')
                            ->label(fn (Get $get): string => $get('ja_pago') ? 'Data do pagamento' : 'Vencimento')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required(),
                        Money::make('valor')
                            ->label('Valor')
                            ->minValue(0.01)
                            ->required(),
                        Select::make('forma_pagamento')
                            ->label('Forma de pagamento')
                            ->options(FormaPagamento::class),
                        Checkbox::make('ja_pago')
                            ->label('Já pago')
                            ->helperText('O título nasce com o pagamento já registrado.')
                            ->default(false)
                            ->live(),
                    ])
                    ->columns(4)
                    ->default(fn (Compra $record): array => [
                        [
                            'vencimento' => ($record->compra_data_entrada ?? now())->toDateString(),
                            'valor' => number_format((float) $record->compra_valor_total, 2, '.', ''),
                            'forma_pagamento' => null,
                            'ja_pago' => false,
                        ],
                    ])
                    ->addActionLabel('Adicionar parcela')
                    ->deletable()
                    ->minItems(1)
                    ->reorderable(false)
                    ->itemLabel(fn (array $state): ?string => 'R$ '.number_format(self::normalizeMoney($state['valor'] ?? null), 2, ',', '.')
                        .(isset($state['vencimento']) && $state['vencimento'] ? ' — '.Carbon::parse($state['vencimento'])->format('d/m/Y') : '')
                        .(($state['ja_pago'] ?? false) ? ' — Pago' : ''))
                    ->live()
                    ->visible(fn (Get $get): bool => (bool) $get('gerar_conta_pagar'))
                    ->columnSpanFull(),
                Placeholder::make('resumo_parcelas')
                    ->label('Resumo')
                    ->content(function (Get $get, Compra $record): string {
                        $parcelas = collect($get('parcelas') ?? []);
                        $soma = $parcelas->sum(fn (array $parcela): float => self::normalizeMoney($parcela['valor'] ?? null));
                        $jaPago = $parcelas
                            ->filter(fn (array $parcela): bool => (bool) ($parcela['ja_pago'] ?? false))
                            ->sum(fn (array $parcela): float => self::normalizeMoney($parcela['valor'] ?? null));
                        $total = (float) $record->compra_valor_total;
                        $percentual = $total > 0 ? round($soma / $total * 100, 2) : 0.0;

                        return 'Soma das parcelas: R$ '.number_format($soma, 2, ',', '.')
                            .' ('.number_format($percentual, 2, ',', '.').'% do valor da compra, R$ '.number_format($total, 2, ',', '.').')'
                            .' — Já pago: R$ '.number_format($jaPago, 2, ',', '.')
                            .' · A pagar: R$ '.number_format($soma - $jaPago, 2, ',', '.');
                    })
                    ->visible(fn (Get $get): bool => (bool) $get('gerar_conta_pagar'))
                    ->columnSpanFull(),
            ])
            ->action(function (Compra $record, array $data, CompraService $service, Action $action): void {
                $gerar = (bool) ($data['gerar_conta_pagar'] ?? false);

                try {
                    DB::transaction(function () use ($record, $data, $service, $gerar): void {
                        $service->confirmar($record);

                        if ($gerar) {
                            $parcelas = collect($data['parcelas'] ?? [])
                                ->map(fn (array $parcela): array => [
                                    'vencimento' => $parcela['vencimento'] ?? null,
                                    'valor' => $parcela['valor'] ?? 0,
                                    'forma_pagamento' => $parcela['forma_pagamento'] ?? null,
                                    'ja_pago' => (bool) ($parcela['ja_pago'] ?? false),
                                ])
                                ->all();

                            $service->gerarContaPagar($record, [
                                'prazo_pagamento_id' => $data['prazo_pagamento_id'] ?? null,
                                'parcelas' => $parcelas,
                            ]);
                        }
                    });
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível confirmar')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    // Interrompe o ciclo da ação (não dispara redirect/after em caso de falha).
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Compra confirmada')
                    ->body($gerar ? 'Estoque atualizado e conta(s) a pagar gerada(s).' : 'Estoque atualizado.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Calcula vencimento + valor de cada parcela a partir das parcelas do Prazo de
     * Pagamento (dias + percentual), reconciliando o resíduo de arredondamento na
     * maior parcela — mesmo padrão de CompraService::gravarRateio, pra soma bater
     * com o valor esperado (percentuais somando 100%) mesmo com valores quebrados
     * (ex.: compra de R$ 100,01 dividida em 3 parcelas de 33,33%).
     *
     * Quando os percentuais do prazo somam mais de 100% de propósito (juros
     * embutido), a reconciliação preserva essa diferença — só corrige o
     * arredondamento por parcela, não força a soma a fechar em 100% do valor da compra.
     *
     * As linhas retornam com chaves novas (UUID) a cada chamada — não índices
     * sequenciais 0,1,2 — pra forçar o Livewire a recriar cada item do zero em vez
     * de reciclar um nó DOM de posição igual entre a seleção antiga e a nova.
     *
     * Se todas as parcelas derem 0, o problema não está aqui: confira se o Prazo de
     * Pagamento selecionado tem `parcela_percentual` preenchido (> 0) em cada linha —
     * um prazo com percentuais zerados produz parcelas de R$ 0,00 corretamente
     * (matematicamente 0% de qualquer valor é 0).
     *
     * @param  Collection<int, PrazoPagamentoParcela>  $parcelasPrazo
     * @return array<string, array{vencimento: string, valor: string, forma_pagamento: null}>
     */
    private static function calcularParcelas(Collection $parcelasPrazo, Carbon $dataBase, float $valorTotal): array
    {
        $linhas = $parcelasPrazo->map(fn (PrazoPagamentoParcela $parcela): array => [
            'vencimento' => $dataBase->copy()->addDays($parcela->parcela_dias)->toDateString(),
            'valor_bruto' => $valorTotal * ((float) $parcela->parcela_percentual / 100),
        ])->values()->all();

        if ($linhas === []) {
            return [];
        }

        $somaBruta = round(array_sum(array_column($linhas, 'valor_bruto')), 2);
        foreach ($linhas as &$linha) {
            $linha['valor'] = round($linha['valor_bruto'], 2);
        }
        unset($linha);

        $diferenca = round($somaBruta - array_sum(array_column($linhas, 'valor')), 2);
        if (abs($diferenca) >= 0.01) {
            $maiorIndice = collect($linhas)->sortByDesc('valor')->keys()->first();
            $linhas[$maiorIndice]['valor'] = round($linhas[$maiorIndice]['valor'] + $diferenca, 2);
        }

        $resultado = [];
        foreach ($linhas as $linha) {
            $resultado[(string) Str::uuid()] = [
                'vencimento' => $linha['vencimento'],
                'valor' => number_format($linha['valor'], 2, '.', ''),
                'forma_pagamento' => null,
                'ja_pago' => false,
            ];
        }

        return $resultado;
    }

    /** Interpreta o valor bruto do campo data_base (string/Carbon) como Carbon, ou null. */
    private static function parseData(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    /**
     * Money::make() mantém o valor em estado bruto formatado (ex: "1.234,56") enquanto
     * o form não é salvo. Pra somar/exibir em tempo real, precisa converter pro padrão
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
}
