<?php

namespace App\Filament\Resources\SessoesCaixa\Schemas;

use App\Enums\StatusSessaoCaixa;
use App\Filament\Support\ContagemNotasSchema;
use App\Filament\Support\MaquininhaQuickCreateForm;
use App\Models\SessaoCaixa;
use App\Services\SessaoCaixaService;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
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
