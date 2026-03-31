<?php

namespace App\Filament\Resources\Produtos\Schemas;

use App\Enums\ProdutoTipoEnum;
use App\Enums\UnidadeProdutoEnum;
use App\Models\Categoria;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProdutoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // ========== SEÇÃO: INFORMAÇÕES BÁSICAS ==========
            Section::make('Informações Básicas')
                ->description('Dados principais do produto')
                ->icon('heroicon-o-cube')
                ->columnSpan(2)
                ->schema([
                    Grid::make(3)
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                            'lg' => 3,
                        ])
                        ->schema([

                            Grid::make(2)
                                ->columns([
                                    'default' => 1,
                                    'md' => 2,
                                ])
                                ->schema([
                                    Select::make('produto_tipo')
                                        ->label('Tipo do Produto')
                                        ->options(
                                            collect(ProdutoTipoEnum::cases())
                                                ->mapWithKeys(fn($type) => [
                                                    $type->value => $type->label()
                                                ])
                                                ->toArray()
                                        )
                                        ->required()
                                        ->searchable()
                                        ->native(false)
                                        ->helperText('Selecione o tipo'),

                                    Select::make('produto_categoria_id')
                                        ->label('Categoria')
                                        ->options(fn() => Categoria::pluck('categoria_nome', 'id')->toArray())
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->native(false)
                                        ->helperText('Organize em categorias'),
                                ])->columnSpan(2),

                                FileUpload::make('produto_foto')
                                ->label('Foto do Produto')
                                ->disk('public')
                                ->directory('fotos_produtos')
                                ->image()
                                ->imageResizeMode('cover')
                                ->imageCropAspectRatio('1:1')
                                ->imageResizeTargetWidth(500)
                                ->imageResizeTargetHeight(500)
                                ->helperText('Formatos: JPG, PNG. Máx. 5MB')
                                ->columnSpan(1),

                            TextInput::make('produto_descricao')
                                ->label('Descrição')
                                ->placeholder('Digite a descrição do produto')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull()
                                ->helperText('Descrição breve e clara'),

                            TextInput::make('produto_codimentacao')
                                ->label('Código de Alimentação')
                                ->placeholder('Ex: 123456')
                                ->columnSpanFull()
                                ->helperText('Código opcional de rastreamento'),
                        ]),
                ]),

            // ========== SEÇÃO: ESTOQUE E CARDÁPIO ==========
            Section::make('Estoque e Visibilidade')
                ->description('Controle de estoque e exibição')
                ->icon('heroicon-o-list-bullet')
                ->columnSpan(1)
                ->schema([
                    Grid::make(4)
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                            'lg' => 4,
                        ])
                        ->schema([
                            Toggle::make('produto_controla_estoque')
                                ->label('Controla Estoque?')
                                ->inline()
                                ->columnSpan(2)
                                ->required()
                                ->helperText('Ativar controle de estoque'),

                            Toggle::make('produto_cardapio')
                                ->label('Mostrar no Cardápio?')
                                ->inline()
                                ->columnSpan(2)
                                ->helperText('Visível para clientes'),

                            Select::make('produto_unidade_comercial')
                                ->label('Unidade de Venda')
                                ->options(
                                    collect(UnidadeProdutoEnum::cases())
                                        ->mapWithKeys(fn($type) => [
                                            $type->value => $type->label()
                                        ])
                                        ->toArray()
                                )
                                ->columnSpan(2)
                                ->searchable()
                                ->native(false)
                                ->helperText('Un., Kg, L, etc'),

                            TextInput::make('produto_quantidade_minima')
                                ->label('Qtd. Mínima')
                                ->numeric()
                                ->minValue(0)
                                ->columnSpan(1)
                                ->helperText('Qtd. mín. venda'),

                            TextInput::make('produto_quantidade_maxima')
                                ->label('Qtd. Máxima')
                                ->numeric()
                                ->minValue(0)
                                ->columnSpan(1)
                                ->helperText('Qtd. máx. venda'),
                        ]),
                ]),

            // ========== SEÇÃO: PRECIFICAÇÃO ==========
            Section::make('Precificação')
                ->description('Gestão de custos e preços')
                ->icon('heroicon-o-currency-dollar')
                ->columnSpan(3)
                ->schema([
                    Grid::make(3)
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                            'lg' => 3,
                        ])
                        ->schema([
                            TextInput::make('produto_preco_custo')
                                ->label('Preço de Custo')
                                ->numeric()
                                ->step(0.01)
                                ->prefix('R$')
                                ->required()
                                ->reactive()
                                ->columnSpan(1)
                                ->helperText('Custo do produto'),

                            TextInput::make('produto_valor_percentual_venda')
                                ->label('Margem de Lucro (%)')
                                ->numeric()
                                ->step(0.01)
                                ->suffix('%')
                                ->required()
                                ->reactive()
                                ->columnSpan(1)
                                ->helperText('Percentual sobre custo')
                                ->afterStateUpdated(function ($state, $set, $get) {
                                    if ($get('produto_preco_custo') !== null && $state !== null) {
                                        $precoVenda = (float)$get('produto_preco_custo') * (1 + ((float)$state / 100));
                                        $set('produto_preco_venda', round($precoVenda, 2));
                                    }
                                }),

                            TextInput::make('produto_preco_venda')
                                ->label('Preço de Venda')
                                ->numeric()
                                ->step(0.01)
                                ->prefix('R$')
                                ->required()
                                ->disabled()
                                ->reactive()
                                ->columnSpan(1)
                                ->helperText('Cálculo automático'),
                        ]),

                    // Seção de Comissão
                    Fieldset::make('Comissão')
                        ->schema([
                            TextInput::make('produto_valor_percentual_comissao')
                                ->label('% Comissão')
                                ->numeric()
                                ->step(0.01)
                                ->suffix('%')
                                ->columnSpan(1)
                                ->helperText('Percentual de comissão'),

                            TextInput::make('produto_preco_comissao')
                                ->label('Valor da Comissão')
                                ->numeric()
                                ->step(0.01)
                                ->prefix('R$')
                                ->columnSpan(1)
                                ->disabled()
                                ->helperText('Cálculo automático'),
                        ]),
                ]),

            // ========== SEÇÃO: PROMOÇÃO ==========
            Section::make('Promoção')
                ->description('Configure promoções e descontos')
                ->icon('heroicon-o-tag')
                ->collapsible()
                ->collapsed()
                ->columnSpan(3)
                ->schema([
                    Grid::make(2)
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                        ])
                        ->schema([
                            DatePicker::make('produto_data_inicio_promocao')
                                ->label('Data Inicial')
                                ->reactive()
                                ->columnSpan(1)
                                ->helperText('Quando inicia'),

                            DatePicker::make('produto_data_final_promocao')
                                ->label('Data Final')
                                ->reactive()
                                ->columnSpan(1)
                                ->helperText('Quando termina'),

                            TextInput::make('produto_preco_promocional')
                                ->label('Preço Promocional')
                                ->numeric()
                                ->step(0.01)
                                ->prefix('R$')
                                ->reactive()
                                ->columnSpan(1)
                                ->visible(fn($get) => $get('produto_data_inicio_promocao') && $get('produto_data_final_promocao'))
                                ->helperText('Preço da promoção'),
                        ]),
                ]),

            // ========== SEÇÃO: INFORMAÇÕES FISCAIS ==========
            Section::make('Informações Fiscais e Códigos')
                ->description('Dados de tributação e códigos de produto')
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->columnSpan(3)
                ->schema([
                    // Grid de Códigos Principais
                    Fieldset::make('Códigos de Produto')
                        ->columns(1)
                        ->schema([
                            TextInput::make('produto_codigo_EAN')
                                ->label('EAN/GTIN')
                                ->placeholder('SEM GTIN')
                                ->columnSpan(1)
                                ->helperText('Código de barras'),

                            TextInput::make('produto_codigo_NCM')
                                ->required(fn(Get $get): bool => in_array($get('produto_tipo'), ['produzido', 'revenda']))
                                ->label('NCM')
                                ->placeholder('Ex: 12345678')
                                ->maxLength(8)
                                ->columnSpan(1)
                                ->helperText('Nomenclatura comum'),

                            TextInput::make('produto_codigo_CEST')
                                ->label('CEST')
                                ->placeholder('0000000')
                                ->default('0000000')
                                ->columnSpan(1)
                                ->helperText('Código CEST'),
                        ]),

                    // Grid de Fiscalização
                    Fieldset::make('Tributação ICMS')
                        ->schema([
                            TextInput::make('produto_cod_tributacao_icms')
                                ->label('Tributação ICMS')
                                ->columnSpan(2)
                                ->helperText('Regime tributário'),

                            TextInput::make('produto_CFOP')
                                ->required(fn(Get $get): bool => in_array($get('produto_tipo'), ['produzido', 'revenda']))
                                ->label('CFOP')
                                ->columnSpan(1)
                                ->helperText('Código de operação'),

                            TextInput::make('produto_CSOSN')
                                ->required(fn(Get $get): bool => in_array($get('produto_tipo'), ['produzido', 'revenda']))
                                ->label('CSOSN')
                                ->columnSpan(1)
                                ->helperText('Classificação'),

                            TextInput::make('produto_valor_percentual_icms')
                                ->label('% ICMS')
                                ->numeric()
                                ->suffix('%')
                                ->columnSpan(1)
                                ->helperText('Alíquota ICMS'),

                            TextInput::make('produto_valor_percentual_reducao_icms')
                                ->label('% Redução ICMS')
                                ->numeric()
                                ->suffix('%')
                                ->columnSpan(1)
                                ->helperText('Se houver redução'),

                            TextInput::make('produto_cod_origem_mercadoria')
                                ->label('Origem')
                                ->columnSpan(1)
                                ->helperText('País/região origem'),

                            TextInput::make('produto_codigo_beneficio_fiscal_uf')
                                ->label('Benef. Fiscal UF')
                                ->columnSpan(1)
                                ->helperText('Se aplicável'),
                        ]),

                    // Grid de Impostos Federais
                    Fieldset::make('Outros Impostos')
                        ->schema([
                            TextInput::make('produto_valor_percentual_cofins')
                                ->label('% COFINS')
                                ->numeric()
                                ->suffix('%')
                                ->columnSpan(1)
                                ->helperText('Alíquota COFINS'),

                            TextInput::make('produto_valor_percentual_pis')
                                ->label('% PIS')
                                ->numeric()
                                ->suffix('%')
                                ->columnSpan(1)
                                ->helperText('Alíquota PIS'),
                        ]),
                ]),
        ])->columns(3);
    }
}
