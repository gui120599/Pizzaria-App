<?php

namespace App\Filament\Resources\PromocoesCombo\Schemas;

use App\Models\Categoria;
use App\Models\OpcoesEntregas;
use App\Models\Produto;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class PromocaoComboForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('promocombo_nome')
                        ->label('Nome')
                        ->placeholder('2 Pizzas Grandes por R$ 87,99')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('promocombo_prioridade')
                        ->label('Prioridade')
                        ->helperText('Quando duas promoções disputam o mesmo item, vale a de maior prioridade.')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                    TextInput::make('promocombo_descricao')
                        ->label('Descrição')
                        ->helperText('Texto mostrado ao cliente no convite para aproveitar a promoção.')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    FileUpload::make('promocombo_imagem')
                        ->label('Imagem da promoção')
                        ->helperText('Aparece no card de destaque e no aviso ao abrir o cardápio. Horizontal (16:9), JPG ou PNG, até 5MB.')
                        ->disk('public')
                        ->directory('promocoes_combo')
                        ->visibility('public')
                        ->image()
                        ->imageEditor()
                        ->imageResizeMode('cover')
                        ->imageCropAspectRatio('16:9')
                        ->imageResizeTargetWidth(1200)
                        ->imageResizeTargetHeight(675)
                        ->maxSize(5120)
                        ->columnSpanFull(),
                    Toggle::make('promocombo_ativa')
                        ->label('Ativa')
                        ->helperText('Desligue para encerrar a promoção imediatamente, sem apagá-la. Pedidos que já têm o combo o mantêm.')
                        ->default(true),
                ]),

            Section::make('Regra do combo')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('promocombo_quantidade')
                        ->label('Quantidade de itens')
                        ->helperText('Ex.: 2 para "2 pizzas por R$ 87,99".')
                        ->numeric()
                        ->minValue(2)
                        ->maxValue(10)
                        ->default(2)
                        ->required(),
                    Money::make('promocombo_preco')
                        ->label('Preço do conjunto')
                        ->helperText('Bordas e adicionais são cobrados à parte.')
                        ->minValue(0)
                        ->required(),
                    TextInput::make('promocombo_limite_por_pedido')
                        ->label('Combos por pedido')
                        ->helperText('Em branco = sem limite.')
                        ->numeric()
                        ->minValue(1),
                ]),

            Section::make('Vigência')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('promocombo_recorrente')
                        ->label('Recorrente')
                        ->helperText('Em vez de uma janela única, repete em dias da semana fixos (ex.: toda terça e quinta, das 18h às 23h).')
                        ->live()
                        ->columnSpanFull(),
                    DateTimePicker::make('promocombo_inicio')
                        ->label(fn (Get $get): string => $get('promocombo_recorrente') ? 'A partir de' : 'Início')
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('promocombo_fim')
                        ->label('Fim')
                        ->seconds(false)
                        ->after('promocombo_inicio')
                        ->required(fn (Get $get): bool => ! $get('promocombo_recorrente'))
                        ->visible(fn (Get $get): bool => ! $get('promocombo_recorrente')),
                    DatePicker::make('promocombo_data_final_recorrencia')
                        ->label('Repetir até (opcional)')
                        ->helperText('Em branco = para sempre.')
                        ->visible(fn (Get $get): bool => (bool) $get('promocombo_recorrente')),
                    CheckboxList::make('promocombo_dias_semana')
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
                        ->visible(fn (Get $get): bool => (bool) $get('promocombo_recorrente'))
                        ->columnSpanFull(),
                    TimePicker::make('promocombo_hora_inicio')
                        ->label('Horário de início')
                        ->seconds(false)
                        ->visible(fn (Get $get): bool => (bool) $get('promocombo_recorrente')),
                    TimePicker::make('promocombo_hora_fim')
                        ->label('Horário de fim')
                        ->seconds(false)
                        ->helperText('Em branco nos dois = vale o dia inteiro.')
                        ->visible(fn (Get $get): bool => (bool) $get('promocombo_recorrente')),
                ]),

            Section::make('Condições')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('promocombo_aplica_fracionado')
                        ->label('Vale para meia a meia / vários sabores')
                        ->helperText('A pizza conta quando todos os sabores participam da promoção.')
                        ->default(true),
                    Toggle::make('promocombo_cumula_relampago')
                        ->label('Vale com promoção relâmpago')
                        ->helperText('O item em relâmpago entra no combo pelo preço da relâmpago.')
                        ->default(true),
                    Toggle::make('promocombo_cumula_promo_adicional')
                        ->label('Vale com brinde da Promoção Adicional')
                        ->helperText('Desligado, o item que ganhou brinde (ou tem preço de gatilho) fica fora do combo.')
                        ->default(true),
                    Toggle::make('promocombo_sugerir')
                        ->label('Convidar o cliente a completar o combo')
                        ->helperText('Mostra a sugestão quando falta item para fechar o combo.')
                        ->default(true),
                    CheckboxList::make('promocombo_opcoes_entrega')
                        ->label('Opções de entrega aceitas')
                        ->helperText('Em branco = todas, inclusive mesa. Com restrição, pedido de mesa e as opções fora da lista saem pelo preço normal.')
                        ->options(fn (): array => OpcoesEntregas::whereNull('deleted_at')->pluck('opcaoentrega_nome', 'id')->all())
                        ->columns(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Itens participantes')
                ->description('Escolha categorias (as subcategorias entram junto) ou produtos. Marque "Fora da promoção" para excluir um item de uma categoria incluída.')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('participantes')
                        ->relationship()
                        ->label('')
                        ->addActionLabel('Adicionar participante')
                        ->columns(3)
                        ->minItems(1)
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => self::tituloDoParticipante($state))
                        ->schema([
                            Select::make('pcp_categoria_id')
                                ->label('Categoria')
                                ->options(fn (): array => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id')->all())
                                ->searchable()
                                ->live()
                                ->requiredWithout('pcp_produto_id')
                                ->validationMessages(['required_without' => 'Escolha uma categoria ou um produto.']),
                            Select::make('pcp_produto_id')
                                ->label('Produto')
                                ->placeholder('Digite o nome do produto...')
                                ->searchable()
                                ->allowHtml()
                                ->live()
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
                                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (filled($value) && filled($get('pcp_categoria_id'))) {
                                        $fail('Escolha a categoria OU o produto, não os dois.');
                                    }
                                }),
                            Toggle::make('pcp_excluir')
                                ->label('Fora da promoção')
                                ->inline(false)
                                ->default(false),
                        ]),
                ]),
        ]);
    }

    /** @param  array<string, mixed>  $state */
    private static function tituloDoParticipante(array $state): ?string
    {
        $nome = filled($state['pcp_categoria_id'] ?? null)
            ? 'Categoria: '.Categoria::find($state['pcp_categoria_id'])?->categoria_nome
            : (filled($state['pcp_produto_id'] ?? null) ? 'Produto: '.Produto::find($state['pcp_produto_id'])?->nomeExibicao() : null);

        if ($nome === null) {
            return null;
        }

        return ($state['pcp_excluir'] ?? false) ? $nome.' (fora da promoção)' : $nome;
    }

    /** Opção do select com imagem + categoria (mesma blade da promoção relâmpago). */
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
