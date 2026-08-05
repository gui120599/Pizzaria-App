<?php

namespace App\Filament\Resources\Contratos\Schemas;

use App\Enums\FormaPagamento;
use App\Enums\StatusContrato;
use App\Models\Prestador;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ContratoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contrato')
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
                        // fornecedor PJ pode ter a coluna `nome` nula, o que quebra o titleAttribute
                        // do relationship (mesmo gotcha do LancamentoForm).
                        ->options(fn (): array => Prestador::fornecedores()
                            ->orderBy('nome')
                            ->get()
                            ->mapWithKeys(fn (Prestador $p): array => [$p->id => $p->nome_exibicao])
                            ->toArray())
                        ->searchable()
                        ->native(false)
                        ->required(),
                    Select::make('plano_despesa_id')
                        ->label('Conta (Plano de Despesas)')
                        ->relationship('planoDespesa', 'nome')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('numero_documento')
                        ->label('Nº do contrato / documento')
                        ->maxLength(255),
                    Money::make('valor')
                        ->label('Valor mensal')
                        ->minValue(0.01)
                        ->required(),
                    Select::make('dia_vencimento')
                        ->label('Dia de vencimento')
                        ->options(collect(range(1, 31))->mapWithKeys(fn (int $dia): array => [$dia => (string) $dia])->toArray())
                        ->native(false)
                        ->required()
                        ->helperText('Se o mês não tiver esse dia (ex.: 31 em fevereiro), o lançamento vence no último dia do mês.'),
                    Select::make('forma_pagamento')
                        ->label('Forma de pagamento')
                        ->options(FormaPagamento::class),
                ]),

            Section::make('Vigência e status')
                ->columns(2)
                ->schema([
                    DatePicker::make('data_inicio')
                        ->label('Início da vigência')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(now())
                        ->required(),
                    DatePicker::make('data_fim')
                        ->label('Fim da vigência')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->afterOrEqual('data_inicio')
                        ->helperText('Deixe em branco para vigência indeterminada. Ao passar dessa data, o contrato é encerrado automaticamente e para de gerar lançamentos.'),
                    Select::make('status')
                        ->label('Status')
                        ->options(StatusContrato::class)
                        ->default(StatusContrato::Ativo)
                        ->required()
                        ->helperText(fn (Get $get): ?string => $get('status') === StatusContrato::Encerrado->value
                            ? 'Contrato encerrado não gera mais lançamentos.'
                            : null),
                ]),

            Section::make('Observações')
                ->schema([
                    Textarea::make('observacoes')
                        ->label('Observações')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
