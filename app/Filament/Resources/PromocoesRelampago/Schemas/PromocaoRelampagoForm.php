<?php

namespace App\Filament\Resources\PromocoesRelampago\Schemas;

use App\Models\Produto;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PromocaoRelampagoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columns(2)
                ->schema([
                    TextInput::make('promocao_nome')
                        ->label('Nome')
                        ->placeholder('Dia da Pizza')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('promocao_ordem')
                        ->label('Ordem no cardápio')
                        ->numeric()
                        ->default(0),
                    TextInput::make('promocao_descricao')
                        ->label('Descrição')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Toggle::make('promocao_ativa')
                        ->label('Ativa')
                        ->helperText('Desligue para encerrar a promoção imediatamente, sem apagá-la.')
                        ->default(true),
                ]),

            Section::make('Vigência')
                ->columns(2)
                ->schema([
                    DateTimePicker::make('promocao_inicio')
                        ->label('Início')
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('promocao_fim')
                        ->label('Fim')
                        ->seconds(false)
                        ->required()
                        ->after('promocao_inicio'),
                ]),

            Section::make('Limites')
                ->columns(2)
                ->description('O teto do pool vale para a promoção inteira, somando todos os produtos.')
                ->schema([
                    TextInput::make('promocao_qtd_total')
                        ->label('Quantidade total')
                        ->helperText('Em branco = ilimitado.')
                        ->numeric()
                        ->minValue(1),
                    TextInput::make('promocao_limite_por_pedido')
                        ->label('Limite por pedido')
                        ->helperText('Em branco = sem limite por cliente.')
                        ->numeric()
                        ->minValue(1),
                ]),

            Section::make('Sabores')
                ->columns(2)
                ->schema([
                    Toggle::make('promocao_permite_sabores')
                        ->label('Permite meia a meia / terços')
                        ->helperText('A promoção só se aplica quando TODOS os sabores escolhidos fazem parte dela.')
                        ->live(),
                    TextInput::make('promocao_max_sabores')
                        ->label('Máximo de sabores')
                        ->helperText('Em branco = usa o máximo da categoria do produto. Nunca ultrapassa esse valor.')
                        ->numeric()
                        ->minValue(2)
                        ->maxValue(4)
                        ->visible(fn (Get $get): bool => (bool) $get('promocao_permite_sabores')),
                ]),

            Section::make('Exibição no cardápio')
                ->columns(2)
                ->schema([
                    Toggle::make('promocao_exibe_contador')
                        ->label('Mostrar quantidade restante')
                        ->default(true)
                        ->live(),
                    TextInput::make('promocao_limiar_escassez')
                        ->label('Só mostrar quando restarem até')
                        ->helperText('Em branco = mostra desde o início. "Restam 38 de 40" não cria urgência.')
                        ->numeric()
                        ->minValue(1)
                        ->visible(fn (Get $get): bool => (bool) $get('promocao_exibe_contador')),
                ]),

            Section::make('Produtos da promoção')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('promocaoProdutos')
                        ->relationship()
                        ->label('')
                        ->addActionLabel('Adicionar produto')
                        ->columns(3)
                        ->minItems(1)
                        ->schema([
                            Select::make('prp_produto_id')
                                ->label('Produto')
                                ->placeholder('Digite o nome do produto...')
                                ->searchable()
                                ->allowHtml()
                                ->distinct()
                                ->getSearchResultsUsing(fn (string $search): array => Produto::query()
                                    ->where('produto_descricao', 'like', "%{$search}%")
                                    ->with('categoria')
                                    ->orderBy('produto_descricao')
                                    ->limit(20)
                                    ->get()
                                    ->mapWithKeys(fn (Produto $p) => [$p->id => self::renderOpcaoProduto($p)])
                                    ->toArray())
                                ->getOptionLabelUsing(fn ($value): ?string => ($p = Produto::with('categoria')->find($value))
                                    ? self::renderOpcaoProduto($p)
                                    : null)
                                ->native(false)
                                ->required(),
                            TextInput::make('prp_preco_promocional')
                                ->label('Preço promocional')
                                ->prefix('R$')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('prp_qtd_total')
                                ->label('Sublimite')
                                ->helperText('Em branco = só o pool.')
                                ->numeric()
                                ->minValue(1),
                        ]),
                ]),
        ]);
    }

    /**
     * Opção do select com imagem + categoria (mesma blade do balanço/compras).
     * Sem saldo: promoção não tem a ver com estoque físico.
     */
    private static function renderOpcaoProduto(Produto $p): string
    {
        return view('filament.components.select-balanco-produto', [
            'image' => $p->produto_foto ? asset('storage/'.$p->produto_foto) : null,
            'name' => $p->nomeExibicao(),
            'category' => $p->categoria?->categoria_nome ?? '',
            'saldo' => null,
            'saldo_raw' => null,
            'unidade' => null,
        ])->render();
    }
}
