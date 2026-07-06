<?php

namespace App\Filament\Resources\Produtos\Schemas;

use App\Enums\ProdutoTipoEnum;
use App\Enums\UnidadeProdutoEnum;
use App\Filament\Resources\Categorias\CategoriaResource;
use App\Models\Categoria;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ProdutoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Produto')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::abaIdentificacao(),
                    self::abaCardapio()
                    ->visible(fn(Get $get): bool => ! in_array($get('produto_tipo'), ['insumo', 'consumo_interno'])),
                    self::abaPrecificacao(),
                    self::abaEstoque(),
                    self::abaPromocao()
                    ->visible(fn(Get $get): bool => ! in_array($get('produto_tipo'), ['insumo', 'consumo_interno'])),
                    self::abaFiscal(),
                ]),
        ]);
    }

    /** Dados que identificam o produto. */
    private static function abaIdentificacao(): Tab
    {
        return Tab::make('Identificação')
            ->icon('heroicon-o-cube')
            ->columns(9)
            ->schema([

                TextInput::make('produto_descricao')
                    ->label('Descrição')
                    ->placeholder('Ex.: Calabresa, Coca-Cola 2L, Farinha de trigo')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(4),

                Select::make('produto_categoria_id')
                    ->label('Categoria')
                    ->options(fn() => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id')->toArray())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->columnSpan(3)
                    ->createOptionForm(fn (Schema $schema) => CategoriaResource::form($schema))
                    ->createOptionAction(fn (Action $action) => $action
                        ->modalHeading('Cadastrar nova categoria')
                        ->modalWidth('2xl')
                    )
                    ->createOptionUsing(function (array $data): int {
                        return Categoria::create($data)->getKey();
                    }),

                Select::make('produto_tipo')
                    ->label('Tipo do Produto')
                    ->options(collect(ProdutoTipoEnum::cases())->mapWithKeys(fn($t) => [$t->value => $t->label()])->toArray())
                    ->required()
                    ->native(false)
                    ->live()
                    ->columnSpan(2)
                    ->helperText('Produzido, revenda, insumo...'),

                TextInput::make('produto_codimentacao')
                    ->label('Condimentação')
                    ->visible(fn(Get $get): bool => ! in_array($get('produto_tipo'), ['insumo', 'consumo_interno']))
                    ->placeholder('Opcional')
                    ->columnSpanFull()
                    ->helperText('Descrição da ficha técnica para mostrar no cardápio.'),

                FileUpload::make('produto_foto')
                    ->label('Foto do Produto')
                    ->disk('public')
                    ->directory('fotos_produtos')
                    ->image()
                    ->imageEditor()
                    ->imageResizeMode('cover')
                    ->imageCropAspectRatio('1:1')
                    ->imageResizeTargetWidth(500)
                    ->imageResizeTargetHeight(500)
                    ->columnSpanFull()
                    ->helperText('JPG ou PNG, quadrada (1:1). Máx. 5MB'),
            ]);
    }

    /** Como o produto aparece e é vendido no cardápio. */
    private static function abaCardapio(): Tab
    {
        return Tab::make('Cardápio')
            ->icon('heroicon-o-bars-3-bottom-left')
            ->columns(4)
            ->schema([
                Fieldset::make('Exibição no cardápio')
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        Toggle::make('produto_cardapio')
                            ->label('Mostrar no cardápio')
                            ->helperText('Visível para os clientes')
                            ->inline(false)
                            ->columnSpan(1),

                        Toggle::make('produto_destaque_mais_vendidos')
                            ->label('Destaque "Mais Vendidos"')
                            ->default(false)
                            ->inline(false)
                            ->columnSpan(1),
                    ]),
                    
                Fieldset::make('Nome exibido')
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        Toggle::make('produto_exibe_categoria')
                            ->label('Incluir a categoria no nome')
                            ->live()
                            ->inline(false)
                            ->columnSpan(1)
                            ->afterStateUpdated(function (bool $state, Set $set, Get $get): void {
                                if ($state && blank($get('produto_preposicao'))) {
                                    $set('produto_preposicao', Categoria::find($get('produto_categoria_id'))?->categoria_preposicao_padrao);
                                }
                            })
                            ->helperText('Monta "PASTEL DE FRANGO"'),

                        TextInput::make('produto_preposicao')
                            ->label('Preposição')
                            ->placeholder('DE, DO, DA, COM')
                            ->maxLength(20)
                            ->visible(fn(Get $get): bool => (bool) $get('produto_exibe_categoria'))
                            ->dehydrateStateUsing(fn(?string $state): ?string => $state ? mb_strtoupper(trim($state)) : null)
                            ->columnSpan(1)
                            ->helperText('Vazio usa a padrão da categoria'),
                    ]),

                Fieldset::make('Venda')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('produto_unidade_comercial')
                            ->label('Unidade de venda')
                            ->options(collect(UnidadeProdutoEnum::cases())->mapWithKeys(fn($t) => [$t->value => $t->label()])->toArray())
                            ->searchable()
                            ->native(false)
                            ->columnSpan(1)
                            ->helperText('Un., Kg, L...'),

                        TextInput::make('produto_quantidade_minima')
                            ->label('Qtd. mínima por venda')
                            ->numeric()
                            ->minValue(0)
                            ->columnSpan(1),

                        TextInput::make('produto_quantidade_maxima')
                            ->label('Qtd. máxima por venda')
                            ->numeric()
                            ->minValue(0)
                            ->columnSpan(1),
                    ]),


            ]);
    }

    /** Custos, margem e preço de venda. */
    private static function abaPrecificacao(): Tab
    {
        $recalcularVenda = function ($state, Set $set, Get $get): void {
            $custo = (float) $get('produto_preco_custo');
            $margem = (float) $get('produto_valor_percentual_venda');
            $set('produto_preco_venda', round($custo * (1 + ($margem / 100)), 2));
        };

        return Tab::make('Precificação')
            ->icon('heroicon-o-currency-dollar')
            ->columns(3)
            ->schema([
                Money::make('produto_preco_custo')
                    ->label('Preço de Custo')
                    ->required()
                    ->live(true)
                    ->columnSpan(1)
                    ->afterStateUpdated($recalcularVenda),

                TextInput::make('produto_valor_percentual_venda')
                    ->label('Margem de Lucro (%)')
                    ->numeric()
                    ->visible(fn(Get $get): bool => ! in_array($get('produto_tipo'), ['insumo', 'consumo_interno']))
                    ->step(0.01)
                    ->suffix('%')
                    ->required()
                    ->live(true)
                    ->columnSpan(1)
                    ->afterStateUpdated($recalcularVenda),

                TextInput::make('produto_preco_venda')
                    ->label('Preço de Venda')
                    ->visible(fn(Get $get): bool => ! in_array($get('produto_tipo'), ['insumo', 'consumo_interno']))
                    ->numeric()
                    ->step(0.01)
                    ->prefix('R$')
                    ->required()
                    ->disabled()
                    ->dehydrated()
                    ->columnSpan(1)
                    ->helperText('Calculado: custo + margem'),

                Fieldset::make('Comissão')
                    ->visible(fn(Get $get): bool => ! in_array($get('produto_tipo'), ['insumo', 'consumo_interno']))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('produto_valor_percentual_comissao')
                            ->label('% Comissão')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->columnSpan(1),

                        TextInput::make('produto_preco_comissao')
                            ->label('Valor da Comissão')
                            ->numeric()
                            ->step(0.01)
                            ->prefix('R$')
                            ->disabled()
                            ->columnSpan(1)
                            ->helperText('Cálculo automático'),
                    ]),
            ]);
    }

    /** Controle de estoque, lote/validade e ficha técnica. */
    private static function abaEstoque(): Tab
    {
        return Tab::make('Estoque')
            ->icon('heroicon-o-archive-box')
            ->columns(3)
            ->schema([
                Toggle::make('produto_controla_estoque')
                    ->label('Controla estoque')
                    ->required()
                    ->inline(false)
                    ->columnSpan(1)
                    ->helperText('Movimenta saldo e custo médio'),

                Toggle::make('produto_controla_lote')
                    ->label('Controla lote/validade')
                    ->inline(false)
                    ->columnSpan(1)
                    ->helperText('Baixa FEFO (vence primeiro, sai primeiro)'),

                Toggle::make('produto_perecivel')
                    ->label('Perecível')
                    ->inline(false)
                    ->columnSpan(1)
                    ->helperText('Insumo com vencimento'),

                Select::make('produto_unidade_estoque')
                    ->label('Unidade de estoque')
                    ->options(collect(UnidadeProdutoEnum::cases())->mapWithKeys(fn($t) => [$t->value => $t->label()])->toArray())
                    ->searchable()
                    ->native(false)
                    ->columnSpan(1)
                    ->helperText('g, ml, un... (base do custo)'),

                TextInput::make('produto_ficha_rendimento')
                    ->label('Rendimento da ficha técnica')
                    ->numeric()
                    ->step(0.001)
                    ->minValue(0.001)
                    ->default(1)
                    ->columnSpan(2)
                    ->helperText('Quanto a receita produz (ex.: 1 un, ou 5000 ml de molho por batelada)'),
            ]);
    }

    /** Promoção por período. */
    private static function abaPromocao(): Tab
    {
        return Tab::make('Promoção')
            ->icon('heroicon-o-tag')
            ->columns(3)
            ->schema([
                TextInput::make('produto_preco_promocional')
                    ->label('Preço Promocional')
                    ->numeric()
                    ->step(0.01)
                    ->prefix('R$')
                    ->columnSpan(1)
                    ->helperText('Vazio = sem promoção'),

                DatePicker::make('produto_data_inicio_promocao')
                    ->label('Início')
                    ->columnSpan(1),

                DatePicker::make('produto_data_final_promocao')
                    ->label('Fim')
                    ->columnSpan(1),
            ]);
    }

    /** Códigos e tributação fiscal. */
    private static function abaFiscal(): Tab
    {
        $obrigatorioVenda = fn(Get $get): bool => in_array($get('produto_tipo'), ['produzido', 'revenda'], true);

        return Tab::make('Fiscal')
            ->icon('heroicon-o-document-text')
            ->schema([
                Fieldset::make('Códigos')
                    ->columns(3)
                    ->schema([
                        TextInput::make('produto_codigo_EAN')->label('EAN/GTIN')->placeholder('SEM GTIN'),
                        TextInput::make('produto_codigo_NCM')->label('NCM')->placeholder('Ex.: 12345678')->maxLength(8)->required($obrigatorioVenda),
                        TextInput::make('produto_codigo_CEST')->label('CEST')->default('0000000'),
                    ]),

                Fieldset::make('Tributação ICMS')
                    ->columns(3)
                    ->schema([
                        TextInput::make('produto_cod_tributacao_icms')->label('Tributação ICMS'),
                        TextInput::make('produto_CFOP')->label('CFOP')->required($obrigatorioVenda),
                        TextInput::make('produto_CSOSN')->label('CSOSN')->required($obrigatorioVenda),
                        TextInput::make('produto_valor_percentual_icms')->label('% ICMS')->numeric()->suffix('%'),
                        TextInput::make('produto_valor_percentual_reducao_icms')->label('% Redução ICMS')->numeric()->suffix('%'),
                        TextInput::make('produto_cod_origem_mercadoria')->label('Origem'),
                        TextInput::make('produto_codigo_beneficio_fiscal_uf')->label('Benef. Fiscal UF'),
                    ]),

                Fieldset::make('Outros Impostos')
                    ->columns(2)
                    ->schema([
                        TextInput::make('produto_valor_percentual_cofins')->label('% COFINS')->numeric()->suffix('%'),
                        TextInput::make('produto_valor_percentual_pis')->label('% PIS')->numeric()->suffix('%'),
                    ]),
            ]);
    }
}
