<?php

namespace App\Filament\Resources\Lancamentos\Schemas;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Lancamento;
use App\Models\Prestador;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Leandrocfe\FilamentPtbrFormFields\Money;

class LancamentoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Classificação')
                ->columns(2)
                ->schema([
                    ToggleButtons::make('tipo')
                        ->label('Tipo')
                        ->grouped()
                        ->options(TipoLancamento::class)
                        ->required()
                        ->live(),
                    Select::make('status')
                        ->label('Status')
                        // Parcial/Pago são calculados a partir da soma dos pagamentos
                        // (ver Lancamento::recalcularStatus) — não dá pra escolher manualmente.
                        ->options([
                            StatusLancamento::Pendente->value => StatusLancamento::Pendente->getLabel(),
                            StatusLancamento::Cancelado->value => StatusLancamento::Cancelado->getLabel(),
                        ])
                        ->default(StatusLancamento::Pendente)
                        ->helperText('Parcial e Pago são calculados automaticamente pelos pagamentos registrados abaixo.')
                        ->required(),
                    Select::make('plano_despesa_id')
                        ->label('Conta (Plano de Despesas)')
                        ->relationship('planoDespesa', 'nome')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => self::ehTipo($get, TipoLancamento::Pagar))
                        ->required(fn (Get $get, ?Lancamento $record): bool => self::ehTipo($get, TipoLancamento::Pagar) && ! self::temRateio($record))
                        ->disabled(fn (?Lancamento $record): bool => self::temRateio($record))
                        ->dehydrated(fn (?Lancamento $record): bool => ! self::temRateio($record))
                        ->helperText(fn (?Lancamento $record): ?string => self::temRateio($record)
                            ? 'Título rateado entre várias contas (gerado por compra). A classificação por conta não pode ser editada aqui.'
                            : null),
                    Select::make('plano_receita_id')
                        ->label('Conta (Plano de Receitas)')
                        ->relationship('planoReceita', 'nome')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => self::ehTipo($get, TipoLancamento::Receber))
                        ->required(fn (Get $get): bool => self::ehTipo($get, TipoLancamento::Receber)),
                ])
                ->columnSpanFull(),

            Section::make('Dados do lançamento')
                ->columns(2)
                ->schema([
                    Placeholder::make('parcela_contexto')
                        ->label('Parcela')
                        ->content(fn (?Lancamento $record): string => "Parcela {$record?->parcela_numero}/{$record?->parcela_total}"
                            .($record?->prazoPagamento ? " — Prazo \"{$record->prazoPagamento->prazo_pagamento_nome}\"" : ''))
                        ->visible(fn (?Lancamento $record): bool => $record !== null && ($record->parcela_total ?? 0) > 1)
                        ->columnSpanFull(),
                    TextInput::make('descricao')
                        ->label('Descrição')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('favorecido_id')
                        ->label('Fornecedor')
                        // Usa o accessor nome_exibicao (nome_fantasia ?? razao_social ?? nome ?? '—'):
                        // fornecedor PJ pode ter a coluna `nome` nula, o que quebra o titleAttribute do relationship.
                        ->options(fn (): array => Prestador::fornecedores()
                            ->orderBy('nome')
                            ->get()
                            ->mapWithKeys(fn (Prestador $p): array => [$p->id => $p->nome_exibicao])
                            ->toArray())
                        ->searchable()
                        ->native(false)
                        ->visible(fn (Get $get): bool => self::ehTipo($get, TipoLancamento::Pagar)),
                    Select::make('cliente_id')
                        ->label('Cliente')
                        ->relationship(name: 'cliente', titleAttribute: 'cliente_nome')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => self::ehTipo($get, TipoLancamento::Receber)),
                    TextInput::make('numero_documento')
                        ->label('Nº documento')
                        ->maxLength(255),
                    Money::make('valor')
                        ->label('Valor')
                        ->minValue(0)
                        ->required()
                        ->disabled(fn (?Lancamento $record): bool => self::temRateio($record))
                        ->dehydrated(fn (?Lancamento $record): bool => ! self::temRateio($record))
                        ->helperText(fn (?Lancamento $record): ?string => self::temRateio($record)
                            ? 'Valor do título rateado — ajuste pela compra de origem.'
                            : null),
                    DatePicker::make('vencimento')
                        ->label('Vencimento')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),
                ]),

            Section::make('Observações')
                ->schema([
                    Textarea::make('observacoes')
                        ->label('Observações')
                        ->columnSpanFull(),
                ]),

            Section::make('Pagamentos')
                ->description('Um título pode ser quitado em mais de um pagamento (parcial + complemento depois, por exemplo). Pode lançar já na criação do título.')
                ->schema([
                    Placeholder::make('valor_pago_resumo')
                        ->label('Valor pago')
                        ->content(fn (Get $get): string => 'R$ '.number_format(self::somaPagamentos($get), 2, ',', '.')),
                    Placeholder::make('valor_restante_resumo')
                        ->label('Valor restante')
                        ->content(fn (Get $get): string => 'R$ '.number_format(
                            max(0.0, self::normalizeMoney($get('valor')) - self::somaPagamentos($get)),
                            2,
                            ',',
                            '.'
                        )),
                    Repeater::make('pagamentos')
                        ->relationship()
                        ->label('')
                        // A soma de todos os pagamentos (existentes + novos) não pode
                        // ultrapassar o valor do título. Regra no próprio campo porque
                        // ->relationship() não expõe os itens em $data no
                        // mutateFormDataBeforeSave (ver feedback_filament_repeater_relationship_validation).
                        ->rule(function (Get $get): Closure {
                            return function (string $attribute, $value, Closure $fail) use ($get): void {
                                $totalPagamentos = collect($value)
                                    ->sum(fn (array $item): float => self::normalizeMoney($item['valor'] ?? null));
                                $valorTitulo = self::normalizeMoney($get('valor'));

                                if (round($totalPagamentos, 2) > round($valorTitulo, 2) + 0.01) {
                                    $fail(
                                        'A soma dos pagamentos (R$ '.number_format($totalPagamentos, 2, ',', '.').
                                        ') não pode ultrapassar o valor do título (R$ '.number_format($valorTitulo, 2, ',', '.').').'
                                    );
                                }
                            };
                        })
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
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state): ?string => isset($state['valor'])
                            ? 'R$ '.number_format(self::normalizeMoney($state['valor']), 2, ',', '.')
                                .(isset($state['data_pagamento']) ? ' — '.Carbon::parse($state['data_pagamento'])->format('d/m/Y') : '')
                            : 'Novo pagamento')
                        ->collapsible()
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Compara o valor atual do campo "tipo" com o enum desejado.
     *
     * O campo usa options(TipoLancamento::class), o que registra um EnumStateCast:
     * $get('tipo') devolve a INSTÂNCIA do enum (não a string). Comparar direto com
     * ->value falha silenciosamente. Aqui normalizamos ambos os casos (enum ou string).
     */
    private static function ehTipo(Get $get, TipoLancamento $tipo): bool
    {
        $atual = $get('tipo');

        return $atual instanceof TipoLancamento
            ? $atual === $tipo
            : $atual === $tipo->value;
    }

    /**
     * Título a pagar rateado entre várias contas (gerado por compra: plano_despesa_id
     * nulo no cabeçalho de propósito — ver CompraService::gerarContaPagar). A tela de
     * edição só conhece um plano por vez, então esses títulos travam plano_despesa_id
     * e valor para não colapsar o rateio numa única conta nem descolar do total rateado
     * em lancamento_despesas.
     */
    private static function temRateio(?Lancamento $record): bool
    {
        return $record !== null
            && $record->exists
            && $record->tipo === TipoLancamento::Pagar
            && $record->plano_despesa_id === null;
    }

    /** Soma o valor dos itens do repeater "pagamentos" ainda em edição (estado do form). */
    private static function somaPagamentos(Get $get): float
    {
        return collect($get('pagamentos') ?? [])
            ->sum(fn (array $pagamento): float => self::normalizeMoney($pagamento['valor'] ?? null));
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
}
