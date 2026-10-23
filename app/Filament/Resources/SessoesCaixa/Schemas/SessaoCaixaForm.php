<?php

namespace App\Filament\Resources\SessoesCaixa\Schemas;

use App\Enums\StatusSessaoCaixa;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Filament\Support\ContagemNotasSchema;
use App\Filament\Support\MaquininhaQuickCreateForm;
use App\Models\ContaFinanceira;
use App\Models\NotaMoeda;
use App\Models\SessaoCaixa;
use App\Services\SessaoCaixaService;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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

                    // TextEntry (não Money) de propósito: um Money precisaria de alguém
                    // chamando ->default()/->state() de novo a cada mudança nas notas pra
                    // se atualizar, e campos desabilitados não reagem a isso — ficava
                    // sempre travado em R$ 0,00 mesmo com cédulas contadas abaixo. Um
                    // TextEntry reavalia o Get a cada render, igual o Subtotal de cada
                    // linha da Contagem de dinheiro.
                    TextEntry::make('sessaocaixa_saldo_inicial_preview')
                        ->label('Saldo inicial (dinheiro)')
                        ->helperText('Calculado a partir da contagem de cédulas/moedas abaixo.')
                        ->state(fn (Get $get): string => 'R$ '.number_format(self::totalNotas($get('notas')), 2, ',', '.')),

                    Text::make(fn (Get $get): string => self::avisoTroco($get('sessaocaixa_caixa_id'), self::totalNotas($get('notas'))))
                        ->columnSpanFull()
                        ->visible(fn (Get $get, ?SessaoCaixa $record): bool => ! ($record?->exists ?? false) && filled($get('sessaocaixa_caixa_id'))),

                    ContaFinanceiraSelect::make('implantacao_conta_id')
                        ->label('Troco inicial veio de qual conta?')
                        ->semCaixaPdv()
                        ->placeholder('Selecione a conta')
                        ->helperText('Este caixa ainda não tem fechamento com troco: informe de onde saiu o dinheiro da abertura para ele entrar no Caixa PDV.')
                        ->visible(fn (Get $get, ?SessaoCaixa $record): bool => self::pedeOrigemDoTrocoInicial($get, $record)),

                    Toggle::make('implantacao_ajuste')
                        ->label('Lançar como ajuste de implantação (sem conta de origem)')
                        ->visible(fn (Get $get, ?SessaoCaixa $record): bool => self::pedeOrigemDoTrocoInicial($get, $record) && (auth()->user()?->hasRole('Admin') ?? false)),

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
                ->description('Todas as cédulas/moedas do catálogo já vêm listadas — escolha, em cada linha, se prefere informar a quantidade contada ou o valor total.')
                ->schema([
                    ContagemNotasSchema::make(disabled: fn (?SessaoCaixa $record): bool => $record?->exists ?? false),
                ])
                ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false)
                // Sem isso, o Filament reavalia o ->disabled() acima DEPOIS que o registro já
                // foi criado (saveRelationships() roda após handleRecordCreation()), então
                // $record->exists já é true nesse ponto e a Section "trava" antes mesmo de
                // salvar as linhas do Repeater 'notas' — saldo_inicial ficava sempre 0.
                ->saveRelationshipsWhenDisabled(),

            Section::make('Maquininhas')
                ->columnSpanFull()
                ->collapsed()
                ->description('Se alguma maquininha usada neste turno já tem saldo acumulado de um período anterior (ainda não zerado), registre aqui, por categoria — esse valor é abatido automaticamente no fechamento.')
                ->schema([
                    Repeater::make('maquininhas')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Select::make('maquininha_id')
                                ->columnSpanFull()
                                ->label('Maquininha')
                                ->relationship('maquininha', 'nome')
                                ->searchable()
                                ->preload()
                                ->createOptionForm(MaquininhaQuickCreateForm::schema())
                                ->required(),
                            Money::make('valor_debito')->label('Débito já acumulado')->minValue(0)->default(0),
                            Money::make('valor_credito')->label('Crédito já acumulado')->minValue(0)->default(0),
                            Money::make('valor_pix')->label('Pix já acumulado')->minValue(0)->default(0),
                        ])
                        ->columns(3)
                        ->addActionLabel('Adicionar maquininha')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ])
                ->disabled(fn (?SessaoCaixa $record): bool => $record?->exists ?? false)
                // Mesmo motivo do Repeater 'notas' acima — sem isso o carryover das
                // maquininhas informado na abertura nunca era persistido.
                ->saveRelationshipsWhenDisabled(),

            Section::make('Observações')
                ->columnSpanFull()
                ->schema([
                    Textarea::make('sessaocaixa_observacoes')
                        ->label('Observações')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Caixa sem fechamento anterior com troco e dinheiro contado: o troco inicial precisa de uma origem no razão. */
    private static function pedeOrigemDoTrocoInicial(Get $get, ?SessaoCaixa $record): bool
    {
        return ! ($record?->exists ?? false)
            && filled($get('sessaocaixa_caixa_id'))
            && self::totalNotas($get('notas')) > 0
            && ContaFinanceira::caixaPdv() !== null
            && app(SessaoCaixaService::class)->trocoEsperadoNaAbertura((int) $get('sessaocaixa_caixa_id')) === null;
    }

    /** Compara o dinheiro contado com o troco que ficou do último fechamento deste caixa. */
    private static function avisoTroco(mixed $caixaId, float $contado): string
    {
        $esperado = app(SessaoCaixaService::class)->trocoEsperadoNaAbertura((int) $caixaId);

        if ($esperado === null) {
            return '';
        }

        $moeda = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
        $diferenca = round($contado - $esperado, 2);

        if (abs($diferenca) < 0.01) {
            return "Troco deixado no último fechamento: {$moeda($esperado)}. A contagem confere.";
        }

        return "Troco deixado no último fechamento: {$moeda($esperado)}. Diferença na contagem: ".($diferenca > 0 ? 'sobra' : 'falta')." de {$moeda(abs($diferenca))}, que será lançada no Caixa PDV ao abrir.";
    }

    /** Soma quantidade × valor da cédula pra cada linha de 'notas' — mesmo cálculo de SessaoCaixaService::finalizarAbertura(), só que ao vivo, no form. */
    private static function totalNotas(?array $notas): float
    {
        return collect($notas ?? [])->sum(
            fn (array $nota): float => (int) ($nota['quantidade'] ?? 0) * (float) (NotaMoeda::find($nota['nota_moeda_id'] ?? null)?->valor ?? 0),
        );
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
