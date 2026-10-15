<?php

namespace App\Filament\Resources\ContasFinanceiras\Schemas;

use App\Enums\Financeiro\TipoContaFinanceira;
use App\Enums\TipoChavePix;
use App\Models\ContaFinanceira;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ContaFinanceiraForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(80)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->withoutTrashed()),
                    Select::make('tipo')
                        ->label('Tipo')
                        ->options(TipoContaFinanceira::class)
                        ->required()
                        ->live()
                        // Conta com movimentação não muda de tipo (o Caixa PDV tem regras próprias).
                        ->disabled(fn (?ContaFinanceira $record): bool => $record?->movimentacoes()->exists() ?? false)
                        ->helperText('Caixa PDV é a gaveta das sessões de caixa: só pode haver uma, e o saldo dela vem da sessão aberta.'),
                    Toggle::make('permite_saldo_negativo')
                        ->label('Permite saldo negativo')
                        ->helperText('Ex.: conta com cheque especial.')
                        ->inline(false),
                    ColorPicker::make('cor')
                        ->label('Cor'),
                    TextInput::make('ordem')
                        ->label('Ordem')
                        ->numeric()
                        ->integer()
                        ->default(0),
                ]),

            Section::make('Saldo inicial')
                ->description('O saldo atual é o saldo inicial mais as movimentações a partir desta data.')
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::tipo($get) !== TipoContaFinanceira::CaixaPdv)
                ->schema([
                    Money::make('saldo_inicial')
                        ->label('Saldo inicial')
                        ->default('0.00')
                        ->required(),
                    DatePicker::make('data_saldo_inicial')
                        ->label('Data do saldo inicial')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(today())
                        ->required(),
                ]),

            Section::make('Dados bancários e PIX')
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::tipo($get)?->temDadosBancarios() ?? false)
                ->schema([
                    TextInput::make('banco')->label('Banco')->maxLength(60),
                    TextInput::make('agencia')->label('Agência')->maxLength(20),
                    TextInput::make('conta')->label('Conta')->maxLength(30),
                    Select::make('tipo_chave_pix')->label('Tipo da chave PIX')->options(TipoChavePix::class),
                    TextInput::make('chave_pix')->label('Chave PIX')->maxLength(140)->columnSpan(2),
                ]),
        ]);
    }

    private static function tipo(Get $get): ?TipoContaFinanceira
    {
        $tipo = $get('tipo');

        return $tipo instanceof TipoContaFinanceira ? $tipo : TipoContaFinanceira::tryFrom((string) $tipo);
    }
}
