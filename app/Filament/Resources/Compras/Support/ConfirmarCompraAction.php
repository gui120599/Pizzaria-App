<?php

namespace App\Filament\Resources\Compras\Support;

use App\Enums\BaseVencimentoEnum;
use App\Enums\FormaPagamento;
use App\Enums\VencimentoOrigemEnum;
use App\Exceptions\FinanceiroException;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Filament\Resources\PrazosPagamento\Schemas\PrazoPagamentoForm;
use App\Filament\Resources\Prestadors\PrestadorResource;
use App\Models\Compra;
use App\Models\PrazoPagamento;
use App\Services\Compras\SugestaoPagamentoCompra;
use App\Services\CompraService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
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
            ->visible(fn (Compra $record): bool => $record->isRascunho() && ! $record->operacaoImpedeEntrada() && auth()->user()->can('confirm', $record))
            ->modalHeading('Confirmar compra')
            ->modalDescription('Gera as entradas de estoque e recalcula o custo médio. Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Confirmar')
            ->modalWidth('4xl')
            // O $record injetado nos campos do schema pode estar desatualizado em memória
            // (ex.: usuário editou itens na aba "Itens" — um Livewire component separado —
            // e o total foi recalculado no banco, mas a página de edição não recarregou o
            // registro). Sem isso, compra_valor_total fica 0 aqui e as parcelas do prazo
            // de pagamento calculam tudo zerado.
            // A sugestão (duplicatas da NF-e > prazo do fornecedor > parcela única) é
            // calculada uma vez aqui e entra pelo fill(): sem $set encadeado, e cada
            // linha de parcela já nasce com as 6 chaves. fill(array) não aplica os
            // defaults dos campos, então tudo que o modal precisa vem explícito. As datas
            // vão como Carbon à meia-noite: string 'Y-m-d' passa pelo cast do DatePicker
            // (createFromFormat) e ganha a hora atual, o que estoura o maxDate(today()).
            ->mountUsing(function (Compra $record, ?Schema $schema, SugestaoPagamentoCompra $sugestoes): void {
                $record->refresh();
                $sugestao = $sugestoes->para($record);

                $schema?->fill([
                    'data_entrada' => $sugestao->dataEntrada->copy()->startOfDay(),
                    'gerar_conta_pagar' => true,
                    'data_base' => $sugestao->dataBase->copy()->startOfDay(),
                    'prazo_pagamento_id' => $sugestao->prazoPagamentoId,
                    'parcelas' => array_map(
                        fn (array $linha): array => [...$linha, 'vencimento' => Carbon::parse($linha['vencimento'])->startOfDay()],
                        $sugestao->parcelas,
                    ),
                    'vencimento_origem' => $sugestao->origem->value,
                    'base_vencimento' => $sugestao->baseVencimento->value,
                ]);
            })
            ->schema([
                self::campoDataEntrada()
                    ->label('Data de entrada no estoque')
                    ->default(fn (Compra $record) => $record->dataEntradaSugerida())
                    ->helperText('Data registrada nas movimentações e lotes. Notas importadas vêm com o dia da importação — confira se é o dia em que a mercadoria realmente entrou.')
                    ->live()
                    // Com prazo contado da emissão, a data-base não acompanha a entrada.
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if ($get('base_vencimento') !== BaseVencimentoEnum::Emissao->value) {
                            $set('data_base', $state);
                        }
                    }),
                Hidden::make('vencimento_origem')->default(VencimentoOrigemEnum::PrazoEntrada->value),
                Hidden::make('base_vencimento')->default(BaseVencimentoEnum::Entrada->value),
                self::avisoDataEntrada(),
                self::avisoOperacaoFiscal(),
                self::fontesDaSugestao(),
                self::avisosDaSugestao(),
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
                    ->default(fn (Compra $record) => $record->dataEntradaSugerida())
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
                                'parcela_forma_pagamento' => $parcela['parcela_forma_pagamento'] ?? null,
                            ]));

                        return $prazo->id;
                    })
                    ->afterStateUpdated(function (?int $state, Get $get, Set $set, Compra $record, SugestaoPagamentoCompra $sugestoes): void {
                        if ($state === null) {
                            return;
                        }

                        $prazo = PrazoPagamento::with('parcelas')->find($state);
                        if (! $prazo || $prazo->parcelas->isEmpty()) {
                            return;
                        }

                        if ($get('vencimento_origem') === VencimentoOrigemEnum::DuplicatasNf->value) {
                            Notification::make()
                                ->title('Vencimentos da nota descartados')
                                ->body('As parcelas foram recalculadas pelo prazo escolhido, e não mais pelas duplicatas da NF-e.')
                                ->warning()
                                ->send();
                        }

                        $dataBase = self::parseData($get('data_base')) ?? $record->dataEntradaSugerida();
                        $valorTotal = (float) $record->compra_valor_total;

                        $set('parcelas', $sugestoes->parcelasDoPrazo(
                            $prazo,
                            $dataBase,
                            $valorTotal,
                            $record->prestador?->condicaoCompra,
                            $sugestoes->formaDaNota($record),
                        ));
                        $set('vencimento_origem', ($get('base_vencimento') === BaseVencimentoEnum::Emissao->value
                            ? VencimentoOrigemEnum::PrazoEmissao
                            : VencimentoOrigemEnum::PrazoEntrada)->value);
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
                            ->maxDate(fn (Get $get) => $get('ja_pago') ? today() : null)
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
                        // Já pago: de onde saiu o dinheiro (gera a movimentação na conta).
                        ContaFinanceiraSelect::make()
                            ->sugerirForma()
                            ->visible(fn (Get $get): bool => self::exigeContaOrigem($get))
                            ->required(fn (Get $get): bool => self::exigeContaOrigem($get))
                            ->columnSpanFull(),
                        ContaFinanceiraSelect::sessaoCaixa()->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->default(fn (Compra $record): array => [
                        SugestaoPagamentoCompra::linhaParcela($record->dataEntradaSugerida()->toDateString(), (float) $record->compra_valor_total),
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
                        $service->confirmar($record, Carbon::parse($data['data_entrada']));

                        if ($gerar) {
                            $parcelas = collect($data['parcelas'] ?? [])
                                ->map(fn (array $parcela): array => [
                                    'vencimento' => $parcela['vencimento'] ?? null,
                                    'valor' => $parcela['valor'] ?? 0,
                                    'forma_pagamento' => $parcela['forma_pagamento'] ?? null,
                                    'ja_pago' => (bool) ($parcela['ja_pago'] ?? false),
                                    'conta_financeira_id' => $parcela['conta_financeira_id'] ?? null,
                                    'sessao_caixa_id' => $parcela['sessao_caixa_id'] ?? null,
                                ])
                                ->all();

                            $service->gerarContaPagar($record, [
                                'prazo_pagamento_id' => $data['prazo_pagamento_id'] ?? null,
                                'parcelas' => $parcelas,
                            ]);

                            // Origem dos vencimentos: decide, na correção da data de entrada,
                            // se os títulos em aberto acompanham a mudança. Ausente = compra antiga.
                            $origem = VencimentoOrigemEnum::tryFrom((string) ($data['vencimento_origem'] ?? ''));
                            if ($origem !== null) {
                                Compra::query()->whereKey($record->id)->update(['compra_vencimento_origem' => $origem->value]);
                            }
                        }
                    });
                } catch (ValidationException|FinanceiroException $e) {
                    Notification::make()
                        ->title('Não foi possível confirmar')
                        ->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())
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
     * DatePicker de data de entrada no estoque — não futura, não anterior à
     * emissão da nota. Reaproveitado por CorrigirDataEntradaAction.
     */
    public static function campoDataEntrada(): DatePicker
    {
        return DatePicker::make('data_entrada')
            ->native(false)
            ->displayFormat('d/m/Y')
            ->required()
            ->maxDate(today())
            ->minDate(fn (Compra $record) => $record->compra_data_emissao);
    }

    /** Destaca quando a data de entrada escolhida não é hoje — o caso que passa despercebido. */
    public static function avisoDataEntrada(): Placeholder
    {
        return Placeholder::make('aviso_data_entrada')
            ->hiddenLabel()
            ->visible(function (Get $get): bool {
                $data = self::parseData($get('data_entrada'));

                return $data !== null && ! $data->isToday();
            })
            ->content(function (Get $get, Compra $record): HtmlString {
                $data = self::parseData($get('data_entrada'));
                $dias = (int) $data?->copy()->startOfDay()->diffInDays(today());
                $quando = $dias === 1 ? 'há 1 dia' : "há {$dias} dias";
                $regra = $record->dataEntradaPelaRegraDeEmissao() ? ' (regra do fornecedor: data de emissão)' : '';

                return new HtmlString('<div class="rounded-lg bg-warning-50 p-3 text-sm font-medium text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">'
                    .'⚠️ A entrada no estoque será registrada em <strong>'.e($data?->format('d/m/Y')).'</strong> ('.e($quando).')'.e($regra).'. '
                    .'Confira se é a data real em que a mercadoria entrou no estoque.</div>');
            })
            ->columnSpanFull();
    }

    /** De onde veio o pré-preenchimento das parcelas, com atalho para o cadastro do fornecedor. */
    public static function fontesDaSugestao(): Placeholder
    {
        return Placeholder::make('fontes_sugestao')
            ->hiddenLabel()
            ->visible(fn (Get $get, Compra $record, SugestaoPagamentoCompra $sugestoes): bool => (bool) $get('gerar_conta_pagar')
                && $sugestoes->para($record)->fontes !== [])
            ->content(function (Compra $record, SugestaoPagamentoCompra $sugestoes): HtmlString {
                $fontes = implode(' · ', array_map('e', $sugestoes->para($record)->fontes));
                $busca = urlencode((string) $record->prestador?->nome_exibicao);
                $link = '<a href="'.e(PrestadorResource::getUrl('index').'?tableSearch='.$busca).'" target="_blank" class="font-medium underline">editar fornecedor</a>';

                return new HtmlString('<div class="rounded-lg bg-info-50 p-3 text-sm text-info-700 dark:bg-info-400/10 dark:text-info-400">'
                    .'Pré-preenchido por: '.$fontes.'. Confira antes de confirmar ('.$link.').</div>');
            })
            ->columnSpanFull();
    }

    /** Divergências da sugestão (soma das duplicatas, prazo × nota): avisa sem bloquear. */
    public static function avisosDaSugestao(): Placeholder
    {
        return Placeholder::make('avisos_sugestao')
            ->hiddenLabel()
            ->visible(fn (Get $get, Compra $record, SugestaoPagamentoCompra $sugestoes): bool => (bool) $get('gerar_conta_pagar')
                && $sugestoes->para($record)->avisos !== [])
            ->content(fn (Compra $record, SugestaoPagamentoCompra $sugestoes): HtmlString => new HtmlString(
                collect($sugestoes->para($record)->avisos)
                    ->map(fn (string $aviso): string => '<div class="rounded-lg bg-warning-50 p-3 text-sm font-medium text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">⚠️ '.e($aviso).'</div>')
                    ->implode('')
            ))
            ->columnSpanFull();
    }

    /**
     * Avisa (sem bloquear) quando a operação sugerida na importação não é
     * COMPRA ou a confiança não é alta, e quando o total diverge do vNF.
     */
    public static function avisoOperacaoFiscal(): Placeholder
    {
        return Placeholder::make('aviso_operacao_fiscal')
            ->hiddenLabel()
            ->visible(fn (Compra $record): bool => OperacaoFiscalView::avisoOperacao($record) !== null
                || OperacaoFiscalView::avisoDivergenciaValor($record) !== null)
            ->content(fn (Compra $record): HtmlString => new HtmlString(
                (OperacaoFiscalView::avisoOperacao($record)?->toHtml() ?? '')
                .(OperacaoFiscalView::avisoDivergenciaValor($record)?->toHtml() ?? '')
            ))
            ->columnSpanFull();
    }

    /** Conta de origem só se aplica a parcela já paga; Compensação não movimenta conta. */
    private static function exigeContaOrigem(Get $get): bool
    {
        $forma = $get('forma_pagamento');
        $forma = $forma instanceof FormaPagamento ? $forma : FormaPagamento::tryFrom((string) $forma);

        return (bool) $get('ja_pago') && $forma !== FormaPagamento::Compensacao;
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
