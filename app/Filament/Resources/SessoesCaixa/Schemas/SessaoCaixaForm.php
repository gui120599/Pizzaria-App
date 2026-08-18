<?php

namespace App\Filament\Resources\SessoesCaixa\Schemas;

use App\Enums\OperadoraMaquininha;
use App\Enums\StatusSessaoCaixa;
use App\Models\NotaMoeda;
use App\Models\SessaoCaixa;
use App\Services\SessaoCaixaService;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class SessaoCaixaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Abertura')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('sessaocaixa_caixa_id')
                        ->label('Caixa')
                        ->relationship('caixa', 'caixa_nome')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false)
                        ->dehydrated()
                        ->rule(self::semFechamentoConfirmadoRule()),

                    Select::make('sessaocaixa_user_id')
                        ->label('Funcionário')
                        ->relationship('user', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->default(fn (): ?int => auth()->id())
                        ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false)
                        ->dehydrated()
                        ->rule(self::semSessaoAbertaRule()),

                    Money::make('sessaocaixa_saldo_inicial')
                        ->label('Saldo inicial (dinheiro)')
                        ->helperText('Calculado a partir da contagem de cédulas/moedas abaixo.')
                        ->disabled()
                        ->dehydrated(false),

                    Text::make(fn (SessaoCaixa $record): string => sprintf(
                        'Status: %s — Saldo final: R$ %s',
                        StatusSessaoCaixa::from($record->sessaocaixa_status)->getLabel(),
                        number_format((float) $record->sessaocaixa_saldo_final, 2, ',', '.'),
                    ))
                        ->visible(fn (?SessaoCaixa $record): bool => $record?->exists ?? false),
                ]),

            Section::make('Contagem de dinheiro')
                ->columnSpanFull()
                ->collapsed()
                ->description('Todas as cédulas/moedas do catálogo já vêm listadas — informe só a quantidade contada de cada uma.')
                ->schema([
                    Repeater::make('notas')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Hidden::make('nota_moeda_id'),
                            TextEntry::make('nota_moeda_label')
                                ->label('Cédula/Moeda')
                                ->state(fn (Get $get): string => NotaMoeda::find($get('nota_moeda_id'))?->descricao ?? '—'),
                            TextInput::make('quantidade')
                                ->label('Quantidade')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                ->required(),
                            TextEntry::make('subtotal')
                                ->label('Subtotal')
                                ->state(fn (Get $get): string => 'R$ ' . number_format(self::valorNota($get('nota_moeda_id')) * (int) ($get('quantidade') ?? 0), 2, ',', '.')),
                        ])
                        ->columns(3)
                        // Catálogo fixo: uma linha por NotaMoeda, já pré-carregada na criação
                        // (o operador só digita a quantidade — não escolhe/adiciona/remove linhas).
                        ->default(fn (): array => NotaMoeda::ordenadas()->get()->map(fn (NotaMoeda $n): array => [
                            'nota_moeda_id' => $n->id,
                            'quantidade' => 0,
                        ])->toArray())
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false)
                        ->columnSpanFull(),
                ])
                ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false),

            Section::make('Maquininhas')
                ->columnSpanFull()
                ->collapsed()
                ->description('Se alguma maquininha usada neste turno já tem saldo acumulado de um período anterior (ainda não zerado), registre aqui — esse valor é abatido automaticamente no fechamento.')
                ->schema([
                    Repeater::make('maquininhas')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Select::make('maquininha_id')
                                ->label('Maquininha')
                                ->relationship('maquininha', 'nome')
                                ->searchable()
                                ->preload()
                                ->createOptionForm([
                                    TextInput::make('nome')->label('Nome')->required()->maxLength(255),
                                    Select::make('operadora')->label('Operadora')->options(OperadoraMaquininha::class)->required(),
                                ])
                                ->required(),
                            Money::make('saldo_inicial')
                                ->label('Saldo já acumulado (odômetro)')
                                ->minValue(0)
                                ->default(0)
                                ->helperText('Valor que a maquininha ainda não zerou e não pertence a este turno.'),
                        ])
                        ->columns(2)
                        ->addActionLabel('Adicionar maquininha')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ])
                ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false),

            Section::make('Saldo adicional de abertura (raro)')
                ->columnSpanFull()
                ->collapsed()
                ->description('Só preencha se este turno já abre com saldo em cartão/Pix pendente de um período anterior — o normal é deixar zerado.')
                ->schema([
                    Money::make('abertura_valor_debito')->label('Débito')->minValue(0)->default(0),
                    Money::make('abertura_valor_credito')->label('Crédito')->minValue(0)->default(0),
                    Money::make('abertura_valor_pix')->label('Pix')->minValue(0)->default(0),
                ])
                ->columns(3)
                ->visible(fn (?SessaoCaixa $record): bool => ! ($record?->exists ?? false)),

            Section::make('Observações')
                ->columnSpanFull()
                ->schema([
                    Textarea::make('sessaocaixa_observacoes')
                        ->label('Observações')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function valorNota(mixed $notaMoedaId): float
    {
        if (! $notaMoedaId) {
            return 0.0;
        }

        return (float) (NotaMoeda::find($notaMoedaId)?->valor ?? 0);
    }

    /** Bloqueia abrir sessão pra um Caixa cujo último fechamento ainda não foi confirmado — ver SessaoCaixaService. */
    private static function semFechamentoConfirmadoRule(): Closure
    {
        return function (?SessaoCaixa $record) {
            return function (string $attribute, $value, Closure $fail) use ($record): void {
                if ($record?->exists || ! $value) {
                    return;
                }

                $motivo = app(SessaoCaixaService::class)->motivoBloqueioAbertura((int) $value);

                if ($motivo) {
                    $fail($motivo);
                }
            };
        };
    }

    /** Um funcionário não pode ter duas sessões ABERTA ao mesmo tempo — mesma regra de SessaoCaixaController::store. */
    private static function semSessaoAbertaRule(): Closure
    {
        return function (?SessaoCaixa $record) {
            return function (string $attribute, $value, Closure $fail) use ($record): void {
                if ($record?->exists) {
                    return;
                }

                $existeSessaoAberta = SessaoCaixa::query()
                    ->where('sessaocaixa_user_id', $value)
                    ->where('sessaocaixa_status', 'ABERTA')
                    ->exists();

                if ($existeSessaoAberta) {
                    $fail('Este funcionário já possui uma sessão de caixa aberta.');
                }
            };
        };
    }
}
