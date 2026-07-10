<?php

namespace App\Filament\Resources\Lancamentos\Schemas;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Prestador;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

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
                        ->options(StatusLancamento::class)
                        ->default(StatusLancamento::Pendente)
                        ->required(),
                    Select::make('plano_despesa_id')
                        ->label('Conta (Plano de Despesas)')
                        ->relationship('planoDespesa', 'nome')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => self::ehTipo($get, TipoLancamento::Pagar))
                        ->required(fn (Get $get): bool => self::ehTipo($get, TipoLancamento::Pagar)),
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
                    TextInput::make('valor')
                        ->label('Valor')
                        ->numeric()
                        ->prefix('R$')
                        ->minValue(0)
                        ->required(),
                    DatePicker::make('vencimento')
                        ->label('Vencimento')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),
                ]),

            Section::make('Pagamento')
                ->columns(2)
                ->schema([
                    DatePicker::make('data_pagamento')
                        ->label('Data do pagamento / recebimento')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                    Select::make('forma_pagamento')
                        ->label('Forma de pagamento')
                        ->options(FormaPagamento::class),
                    Textarea::make('observacoes')
                        ->label('Observações')
                        ->columnSpanFull(),
                ]),
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
}
