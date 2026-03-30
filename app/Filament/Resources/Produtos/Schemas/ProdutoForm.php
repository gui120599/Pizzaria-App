<?php

namespace App\Filament\Resources\Produtos\Schemas;

use App\Enums\ProdutoTipoEnum;
use App\Enums\UnidadeProdutoEnum;
use App\Models\Categoria;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProdutoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Fieldset::make()->schema([
                    // main product info
                    TextInput::make('produto_descricao')
                        ->label('Descrição')
                        ->required(),

                    Select::make('produto_tipo')
                        ->options(
                            collect(ProdutoTipoEnum::cases())
                                ->mapWithKeys(fn($type) => [
                                    $type->value => $type->label()
                                ])
                                ->toArray()
                        )
                        ->label('Tipo')
                        ->required(),

                    Select::make('produto_categoria_id')
                        ->label('Categoria')
                        ->options(fn() => Categoria::pluck('categoria_nome', 'id')->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),

                    FileUpload::make('produto_foto')
                        ->label('Fotos do produto')
                         ->disk('fotos_produtos')   // 👈 disco personalizado
    ->directory('')
                        ->image()
                        ->multiple()
                        ->maxFiles(5)
                        ->imageResizeMode('cover'),
                ])->columnSpan(2),

                Fieldset::make()->schema([
                    // pricing and stock info
                    Toggle::make('produto_controla_estoque')
                        ->label('Controla estoque')
                        ->required()
                        ->inline(),

                    TextInput::make('produto_quantidade_minima')
                        ->label('Qtd. mínima')
                        ->numeric()
                        ->helperText('Quantidade mínima permitida na venda'),

                    TextInput::make('produto_quantidade_maxima')
                        ->label('Qtd. máxima')
                        ->numeric(),

                    Select::make('produto_unidade_comercial')
                        ->label('Unidade')
                        ->searchable()
                        ->options(
                            collect(UnidadeProdutoEnum::cases())
                                ->mapWithKeys(fn($type) => [
                                    $type->value => $type->label()
                                ])
                                ->toArray()
                        ),

                    TextInput::make('produto_preco_custo')
                        ->label('Preço de custo')
                        ->numeric()
                        ->prefix('R$')
                        ->reactive(),

                    TextInput::make('produto_valor_percentual_venda')
                        ->label('% margem')
                        ->numeric()
                        ->reactive()
                        ->helperText('Margem percentual aplicada ao custo')
                        ->afterStateUpdated(function ($state, $set, $get) {
                            if ($get('produto_preco_custo') !== null && $state !== null) {
                                $set('produto_preco_venda', round($get('produto_preco_custo') * (1 + ($state / 100)), 2));
                            }
                        }),

                    TextInput::make('produto_preco_venda')
                        ->label('Preço de venda')
                        ->numeric()
                        ->prefix('R$')
                        ->helperText('Calculado a partir do custo e margem')
                        ->disabled()
                        ->reactive(),

                    Toggle::make('produto_cardapio')
                        ->label('Mostrar no cardápio')
                        ->inline(),

                    DatePicker::make('produto_data_inicio_promocao')
                        ->label('Início da promoção')
                        ->reactive(),

                    DatePicker::make('produto_data_final_promocao')
                        ->label('Fim da promoção')
                        ->reactive(),

                    TextInput::make('produto_preco_promocional')
                        ->label('Preço promocional')
                        ->numeric()
                        ->prefix('R$')
                        ->reactive()
                        ->visible(fn($get) => $get('produto_data_inicio_promocao') && $get('produto_data_final_promocao')),
                ])->columnSpan(1),
            ]),

            Section::make('Fiscal e códigos')->collapsible()->schema([
                Grid::make(3)->schema([
                    TextInput::make('produto_codigo_NCM')->label('NCM')->maxLength(8),
                    TextInput::make('produto_codigo_CEST')->label('CEST')->default('0000000'),
                    TextInput::make('produto_codigo_EAN')->label('EAN/GTIN')->default('SEM GTIN'),
                    TextInput::make('produto_codimentacao')->label('Cod. Alimentação'),
                    TextInput::make('produto_CFOP')->label('CFOP'),
                    TextInput::make('produto_CSOSN')->label('CSOSN'),
                    TextInput::make('produto_cod_origem_mercadoria')->label('Origem'),
                    TextInput::make('produto_cod_tributacao_icms')->label('Tributação ICMS'),
                    TextInput::make('produto_codigo_beneficio_fiscal_uf')->label('Benefício fiscal UF'),
                ]),

                Grid::make(3)->schema([
                    TextInput::make('produto_valor_percentual_icms')->label('% ICMS')->numeric()->suffix('%'),
                    TextInput::make('produto_valor_percentual_cofins')->label('% COFINS')->numeric()->suffix('%'),
                    TextInput::make('produto_valor_percentual_pis')->label('% PIS')->numeric()->suffix('%'),
                    TextInput::make('produto_valor_percentual_reducao_icms')->label('% Redução ICMS')->numeric()->suffix('%'),
                ]),
            ]),
        ])
            ->columns(1);
    }
}
