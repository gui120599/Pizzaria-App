<?php

namespace App\Filament\Resources\PromocoesAdicionais\Schemas;

use App\Models\Produto;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class PromocaoAdicionalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('promoad_nome')
                        ->label('Nome')
                        ->placeholder('Dia do Cliente')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('promoad_ordem')
                        ->label('Ordem')
                        ->numeric()
                        ->default(0),
                    TextInput::make('promoad_descricao')
                        ->label('Descrição')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Toggle::make('promoad_ativa')
                        ->label('Ativa')
                        ->helperText('Desligue para encerrar a promoção imediatamente, sem apagá-la.')
                        ->default(true),
                ]),

            Section::make('Restrições')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('promoad_aplica_fracionado')
                        ->label('Vale também para meia a meia / três sabores')
                        ->helperText('Desligado (padrão): só pizza inteira. Ligado: se TODOS os sabores escolhidos tiverem override configurado nesta campanha, o preço anunciado da pizza usa o maior override entre eles.')
                        ->default(false)
                        ->columnSpanFull(),
                    CheckboxList::make('opcoesPagamento')
                        ->relationship('opcoesPagamento', 'opcaopag_nome')
                        ->label('Formas de pagamento aceitas')
                        ->helperText('Em branco = todas as formas valem. Só é checado no cardápio público — atendente e garçom não escolhem forma de pagamento no momento do item.')
                        ->columns(2)
                        ->bulkToggleable()
                        ->columnSpanFull(),
                ]),

            Section::make('Vigência')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('promoad_recorrente')
                        ->label('Recorrente')
                        ->helperText('Em vez de uma janela única, repete em dias da semana fixos (ex.: toda terça e quinta, das 18h às 20h).')
                        ->live()
                        ->columnSpanFull(),

                    DateTimePicker::make('promoad_inicio')
                        ->label(fn (Get $get): string => $get('promoad_recorrente') ? 'A partir de' : 'Início')
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('promoad_fim')
                        ->label('Fim')
                        ->seconds(false)
                        ->after('promoad_inicio')
                        ->required(fn (Get $get): bool => ! $get('promoad_recorrente'))
                        ->visible(fn (Get $get): bool => ! $get('promoad_recorrente')),

                    DatePicker::make('promoad_data_final_recorrencia')
                        ->label('Repetir até (opcional)')
                        ->helperText('Em branco = para sempre.')
                        ->visible(fn (Get $get): bool => (bool) $get('promoad_recorrente')),
                    CheckboxList::make('promoad_dias_semana')
                        ->label('Dias da semana')
                        ->helperText('Em branco = todos os dias.')
                        ->options([
                            0 => 'Domingo',
                            1 => 'Segunda',
                            2 => 'Terça',
                            3 => 'Quarta',
                            4 => 'Quinta',
                            5 => 'Sexta',
                            6 => 'Sábado',
                        ])
                        ->columns(4)
                        ->bulkToggleable()
                        ->visible(fn (Get $get): bool => (bool) $get('promoad_recorrente'))
                        ->columnSpanFull(),
                    TimePicker::make('promoad_hora_inicio')
                        ->label('Horário de início')
                        ->seconds(false)
                        ->visible(fn (Get $get): bool => (bool) $get('promoad_recorrente')),
                    TimePicker::make('promoad_hora_fim')
                        ->label('Horário de fim')
                        ->seconds(false)
                        ->helperText('Em branco nos dois = vale o dia inteiro.')
                        ->visible(fn (Get $get): bool => (bool) $get('promoad_recorrente')),
                ]),

            Section::make('Regras (produto gatilho → ofertas)')
                ->description('Cada regra é um produto-gatilho da campanha. Dentro dela, cadastre as opções de produto que podem ser oferecidas — o cliente escolhe 1 dentre elas.')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('regras')
                        ->relationship()
                        ->label('')
                        ->addActionLabel('Adicionar produto-gatilho')
                        ->columns(3)
                        ->minItems(1)
                        ->schema([
                            Select::make('par_produto_gatilho_id')
                                ->label('Produto gatilho')
                                ->helperText('Ao ser adicionado ao pedido, oferece a promoção.')
                                ->placeholder('Digite o nome do produto...')
                                ->searchable()
                                ->allowHtml()
                                ->getSearchResultsUsing(fn (string $search): array => self::opcoesProduto($search))
                                ->getOptionLabelUsing(fn ($value): ?string => self::opcaoProdutoSelecionado($value))
                                ->native(false)
                                ->required()
                                ->columnSpan(2),
                            Money::make('par_preco_gatilho_override')
                                ->label('Preço do gatilho nesta campanha (opcional)')
                                ->helperText('Em branco = o preço não muda; a promoção só soma a oferta escolhida. Quando preenchido, vence qualquer outro preço/promoção do produto.')
                                ->minValue(0),
                            TextInput::make('par_qtd_maxima_por_pedido')
                                ->label('Máx. aceites por pedido')
                                ->helperText('Quantas vezes o cliente pode aceitar alguma das ofertas abaixo no mesmo pedido. Em branco = sem limite.')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->columnSpanFull(),

                            Repeater::make('ofertas')
                                ->relationship()
                                ->label('Produtos oferecidos')
                                ->addActionLabel('Adicionar produto oferecido')
                                ->columns(3)
                                ->minItems(1)
                                ->columnSpanFull()
                                ->schema([
                                    Select::make('pao_produto_oferta_id')
                                        ->label('Produto oferecido')
                                        ->helperText('O cliente escolhe no máximo 1 das opções cadastradas aqui.')
                                        ->placeholder('Digite o nome do produto...')
                                        ->searchable()
                                        ->allowHtml()
                                        ->getSearchResultsUsing(fn (string $search): array => self::opcoesProduto($search))
                                        ->getOptionLabelUsing(fn ($value): ?string => self::opcaoProdutoSelecionado($value))
                                        ->native(false)
                                        ->required(),
                                    // TextInput numérico "cru" em vez de Money::make() aqui de propósito:
                                    // o pacote leandrocfe/filament-ptbr-form-fields quebra
                                    // ("$container must not be accessed before initialization") quando
                                    // usado num Repeater aninhado dentro de outro Repeater — sua
                                    // extraAlpineAttributes() chama getStatePath() cedo demais no
                                    // template do 2º nível. Ver PromocoesAdicionaisTable/plano.
                                    TextInput::make('pao_valor_adicional')
                                        ->label('Valor adicional')
                                        ->helperText('Quanto o cliente paga a mais por esta opção (ex.: 5.00).')
                                        ->numeric()
                                        ->prefix('R$')
                                        ->step(0.01)
                                        ->minValue(0)
                                        ->required(),
                                    TextInput::make('pao_qtd_total')
                                        ->label('Quantidade total desta oferta')
                                        ->helperText('Em branco = ilimitado.')
                                        ->numeric()
                                        ->minValue(1),
                                ]),
                        ]),
                ]),
        ]);
    }

    /** @return array<int, string> */
    private static function opcoesProduto(string $search): array
    {
        return Produto::query()
            ->where('produto_descricao', 'like', "%{$search}%")
            ->with('categoria')
            ->orderBy('produto_descricao')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (Produto $p) => [$p->id => self::renderOpcaoProduto($p)])
            ->toArray();
    }

    private static function opcaoProdutoSelecionado($value): ?string
    {
        $produto = Produto::with('categoria')->find($value);

        return $produto ? self::renderOpcaoProduto($produto) : null;
    }

    /**
     * Opção do select com imagem + categoria (mesma blade usada em
     * balanço/compras e na Promoção Relâmpago).
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
