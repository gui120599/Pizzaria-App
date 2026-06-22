<?php

namespace App\Filament\Resources\Compras\Schemas;

use App\Models\CentroCusto;
use App\Models\Compra;
use App\Models\Produto;
use App\Models\Prestador;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CompraForm
{
    public static function configure(Schema $schema): Schema
    {
        $bloqueado = fn (?Compra $record): bool => $record !== null && ! $record->isRascunho();

        return $schema
            ->components([
                Section::make('Dados da Nota')
                    ->icon('heroicon-o-document-text')
                    ->columns(3)
                    ->disabled($bloqueado)
                    ->schema([
                        Select::make('compra_prestador_id')
                            ->label('Fornecedor')
                            ->options(fn (): array => Prestador::fornecedores()
                                ->orderBy('nome')
                                ->get()
                                ->mapWithKeys(fn (Prestador $p) => [$p->id => $p->nome_exibicao])
                                ->toArray())
                            ->searchable()
                            ->native(false)
                            ->columnSpan(3),

                        TextInput::make('compra_numero')->label('Número NF'),
                        TextInput::make('compra_serie')->label('Série'),
                        DatePicker::make('compra_data_emissao')->label('Emissão'),
                        DatePicker::make('compra_data_entrada')->label('Entrada no estoque')->default(now()),

                        Select::make('compra_centro_custo_id')
                            ->label('Centro de custo')
                            ->options(fn (): array => CentroCusto::query()->orderBy('centro_custo_nome')->pluck('centro_custo_nome', 'id')->toArray())
                            ->searchable()
                            ->placeholder('Opcional'),

                        Textarea::make('compra_observacao')->label('Observação')->rows(2)->columnSpanFull(),
                    ]),

                Section::make('Itens')
                    ->icon('heroicon-o-list-bullet')
                    ->disabled($bloqueado)
                    ->schema([
                        Repeater::make('itens')
                            ->relationship()
                            ->label('')
                            ->addActionLabel('Adicionar item')
                            ->columns(4)
                            ->itemLabel(fn (array $state): ?string => $state['ci_descricao_fornecedor'] ?? null)
                            ->schema([
                                Select::make('ci_produto_id')
                                    ->label('Insumo')
                                    ->options(fn (): array => Produto::orderBy('produto_descricao')->pluck('produto_descricao', 'id')->toArray())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        $insumo = $state ? Produto::find($state) : null;
                                        if ($insumo) {
                                            $set('ci_unidade_compra', $insumo->produto_unidade_estoque);
                                        }
                                    })
                                    ->columnSpan(2),

                                TextInput::make('ci_codigo_fornecedor')->label('Cód. forn.')->columnSpan(1),
                                TextInput::make('ci_descricao_fornecedor')->label('Descrição na NF')->columnSpan(1),

                                TextInput::make('ci_quantidade_compra')
                                    ->label('Qtd. compra')
                                    ->numeric()->step(0.0001)->minValue(0.0001)->required()->default(1),
                                TextInput::make('ci_unidade_compra')->label('Un. compra'),
                                TextInput::make('ci_fator_conversao')
                                    ->label('Fator p/ estoque')
                                    ->numeric()->step(0.0001)->minValue(0.0001)->default(1)->required()
                                    ->helperText('1 un. compra = X un. estoque'),
                                TextInput::make('ci_custo_unitario_compra')
                                    ->label('Custo unit.')
                                    ->numeric()->step(0.0001)->minValue(0)->required()->prefix('R$'),

                                TextInput::make('ci_lote_codigo')
                                    ->label('Lote')
                                    ->visible(fn (Get $get): bool => (bool) optional(Produto::find($get('ci_produto_id')))->produto_controla_lote)
                                    ->columnSpan(2),
                                DatePicker::make('ci_validade')
                                    ->label('Validade')
                                    ->visible(fn (Get $get): bool => (bool) optional(Produto::find($get('ci_produto_id')))->produto_controla_lote)
                                    ->columnSpan(2),
                            ]),
                    ]),

                Section::make('Valores')
                    ->icon('heroicon-o-currency-dollar')
                    ->columns(3)
                    ->disabled($bloqueado)
                    ->schema([
                        TextInput::make('compra_valor_frete')->label('Frete')->numeric()->step(0.01)->default(0)->prefix('R$'),
                        TextInput::make('compra_valor_desconto')->label('Desconto')->numeric()->step(0.01)->default(0)->prefix('R$'),
                        TextInput::make('compra_valor_outros')->label('Outras despesas')->numeric()->step(0.01)->default(0)->prefix('R$'),
                    ]),
            ]);
    }
}
