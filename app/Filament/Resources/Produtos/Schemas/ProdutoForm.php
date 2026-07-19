<?php

namespace App\Filament\Resources\Produtos\Schemas;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\UnidadeProdutoEnum;
use App\Filament\Resources\Categorias\CategoriaResource;
use App\Models\Categoria;
use App\Models\PlanoDespesa;
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
                        ->visible(fn (Get $get): bool => ! self::naoVendidoDireto($get)),
                    self::abaPrecificacao(),
                    self::abaEstoque(),
                    self::abaPromocao()
                        ->visible(fn (Get $get): bool => ! self::naoVendidoDireto($get)),
                    self::abaFiscal(),
                ]),
        ]);
    }

    /** Tipos de produto que entram numa compra e portanto têm custo próprio a classificar. */
    private static function ehComprado(Get $get): bool
    {
        return in_array($get('produto_tipo'), ['insumo', 'revenda', 'consumo_interno'], true);
    }

    /** Tipos que não são vendidos diretamente ao cliente (não têm cardápio, preço de venda ou promoção próprios). */
    private static function naoVendidoDireto(Get $get): bool
    {
        return in_array($get('produto_tipo'), ['insumo', 'consumo_interno', 'insumo_produzido'], true);
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
                    ->options(fn () => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id')->toArray())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->columnSpan(3)
                    ->createOptionForm(fn (Schema $schema) => CategoriaResource::form($schema))
                    ->createOptionAction(
                        fn (Action $action) => $action
                            ->modalHeading('Cadastrar nova categoria')
                            ->modalWidth('2xl')
                    )
                    ->createOptionUsing(function (array $data): int {
                        return Categoria::create($data)->getKey();
                    }),

                Select::make('produto_tipo')
                    ->label('Tipo do Produto')
                    ->options(collect(ProdutoTipoEnum::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->toArray())
                    ->required()
                    ->native(false)
                    ->live()
                    ->columnSpan(2)
                    ->helperText('Produzido, revenda, insumo...'),

                TextInput::make('produto_codimentacao')
                    ->label('Condimentação')
                    ->visible(fn (Get $get): bool => ! self::naoVendidoDireto($get))
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
                            ->visible(fn (Get $get): bool => (bool) $get('produto_exibe_categoria'))
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? mb_strtoupper(trim($state)) : null)
                            ->columnSpan(1)
                            ->helperText('Vazio usa a padrão da categoria'),
                    ]),

                Fieldset::make('Venda')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('produto_unidade_comercial')
                            ->label('Unidade de venda')
                            ->options(collect(UnidadeProdutoEnum::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->toArray())
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
        // O componente Money mantém o state ao vivo já mascarado em pt-BR (ex.: "0,80"),
        // só convertendo para número no dehydrate (submit). Um (float) direto nessa string
        // para no separador decimal e zera qualquer custo menor que R$ 1 (ex.: "0,80" -> 0.0).
        $parseMoeda = fn ($valor): float => ((int) str_replace([',', '.'], '', (string) $valor)) / 100;

        // $set() não passa pelo formatStateUsing do Money, então quem escreve no campo
        // precisa formatar no mesmo padrão pt-BR que ele exibiria sozinho (ex.: "1,20").
        $formatMoeda = fn (float $valor): string => number_format($valor, 2, ',', '.');

        // Modo automático: custo + margem definem o preço de venda.
        $recalcularVenda = function ($state, Set $set, Get $get) use ($parseMoeda, $formatMoeda): void {
            if ($get('produto_venda_manual')) {
                return;
            }
            $custo = $parseMoeda($get('produto_preco_custo'));
            $margem = (float) $get('produto_valor_percentual_venda');
            $set('produto_preco_venda', $formatMoeda(round($custo * (1 + ($margem / 100)), 2)));
        };

        // Modo manual: custo + preço de venda digitado definem a margem.
        $recalcularMargem = function ($state, Set $set, Get $get) use ($parseMoeda): void {
            if (! $get('produto_venda_manual')) {
                return;
            }
            $custo = $parseMoeda($get('produto_preco_custo'));
            $venda = $parseMoeda($get('produto_preco_venda'));
            $set('produto_valor_percentual_venda', $custo > 0 ? round((($venda / $custo) - 1) * 100, 2) : 0);
        };

        return Tab::make('Precificação')
            ->icon('heroicon-o-currency-dollar')
            ->columns(3)
            ->schema([
                Toggle::make('produto_venda_manual')
                    ->label('Definir preço de venda manualmente')
                    ->helperText('Desligado: você define a margem e o sistema calcula o preço de venda. Ligado: você define o preço de venda e o sistema calcula a margem.')
                    ->default(false)
                    ->visible(fn (Get $get): bool => ! self::naoVendidoDireto($get))
                    ->live()
                    ->inline(false)
                    ->columnSpanFull()
                    ->afterStateUpdated(function (bool $state, Set $set, Get $get) use ($recalcularVenda, $recalcularMargem): void {
                        // Ao trocar de modo, recalcula o lado que ficou "travado" a partir do outro,
                        // em vez de deixar o valor antigo (potencialmente inconsistente) parado ali.
                        $state ? $recalcularMargem(null, $set, $get) : $recalcularVenda(null, $set, $get);
                    }),

                Money::make('produto_preco_custo')
                    ->label('Preço de Custo')
                    ->required()
                    ->live(true)
                    ->columnSpan(1)
                    ->afterStateUpdated(function ($state, Set $set, Get $get) use ($recalcularVenda, $recalcularMargem): void {
                        $recalcularVenda($state, $set, $get);
                        $recalcularMargem($state, $set, $get);
                    }),

                TextInput::make('produto_valor_percentual_venda')
                    ->label('Margem de Lucro (%)')
                    ->numeric()
                    ->visible(fn (Get $get): bool => ! self::naoVendidoDireto($get))
                    ->step(0.01)
                    ->suffix('%')
                    ->required()
                    ->live(true)
                    ->disabled(fn (Get $get): bool => (bool) $get('produto_venda_manual'))
                    ->dehydrated()
                    ->columnSpan(1)
                    ->afterStateUpdated($recalcularVenda),

                Money::make('produto_preco_venda')
                    ->label('Preço de Venda')
                    ->visible(fn (Get $get): bool => ! self::naoVendidoDireto($get))
                    ->required()
                    ->live(true)
                    ->disabled(fn (Get $get): bool => ! $get('produto_venda_manual'))
                    ->dehydrated()
                    ->columnSpan(1)
                    ->afterStateUpdated($recalcularMargem)
                    ->helperText(fn (Get $get): string => $get('produto_venda_manual') ? 'Margem calculada automaticamente' : 'Calculado: custo + margem'),

                Fieldset::make('Comissão')
                    ->visible(fn (Get $get): bool => ! self::naoVendidoDireto($get))
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

                // Usa options() (não relationship): a ProdutoForm é reaproveitada como
                // createOptionForm dentro do relation manager de itens da compra, cujo record
                // é CompraItem — e ->relationship('planoDespesa') seria resolvido nesse modelo errado.
                Select::make('produto_plano_despesa_id')
                    ->label('Plano de despesa')
                    ->options(fn (): array => PlanoDespesa::orderBy('nome')->pluck('nome', 'id')->toArray())
                    ->searchable()
                    ->native(false)
                    // Só produtos que entram numa compra têm custo a classificar. Produto
                    // produzido tem o custo classificado via os insumos da ficha técnica.
                    ->visible(self::ehComprado(...))
                    ->required(self::ehComprado(...))
                    ->helperText('Classificação do custo deste produto na DRE. Usado ao gerar a conta a pagar de uma compra.'),
            ]);
    }

    /** Controle de estoque, lote/validade e ficha técnica — agrupados por modalidade. */
    private static function abaEstoque(): Tab
    {
        return Tab::make('Estoque')
            ->icon('heroicon-o-archive-box')
            ->columns(1)
            ->schema([
                Fieldset::make('Controle de Estoque')
                    ->columns(3)
                    ->schema([
                        Toggle::make('produto_controla_estoque')
                            ->label('Controla estoque')
                            ->live()
                            ->required()
                            ->inline(false)
                            ->columnSpan(1)
                            ->helperText('Movimenta saldo e custo médio'),

                        Select::make('produto_modo_controle_estoque')
                            ->label('Modo de controle')
                            ->options(collect(EstoqueModoControleEnum::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->toArray())
                            ->default(EstoqueModoControleEnum::NAO_CONTROLAR->value)
                            ->native(false)
                            ->required()
                            ->columnSpan(1)
                            ->visible(fn (Get $get): bool => (bool) $get('produto_controla_estoque'))
                            ->helperText('O que fazer ao vender sem saldo suficiente'),

                        Toggle::make('produto_lista_estoque_zerado')
                            ->label('Listar no cardápio zerado')
                            ->default(true)
                            ->inline(false)
                            ->columnSpan(1)
                            ->visible(fn (Get $get): bool => (bool) $get('produto_controla_estoque'))
                            ->helperText('Desligado: some do cardápio quando o saldo chegar a zero'),
                    ]),

                Fieldset::make('Lote e Validade (FEFO)')
                    ->columns(2)
                    ->schema([
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
                    ]),

                Fieldset::make('Unidade e Ficha Técnica')
                    ->columns(2)
                    ->schema([
                        Select::make('produto_unidade_estoque')
                            ->label('Unidade de estoque')
                            ->options(collect(UnidadeProdutoEnum::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->toArray())
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
                            ->columnSpan(1)
                            ->helperText('Quanto a receita produz (ex.: 1 un, ou 5000 ml de molho por batelada)'),
                    ]),
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
        $obrigatorioVenda = fn (Get $get): bool => in_array($get('produto_tipo'), ['produzido', 'revenda'], true);

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
