<?php

namespace App\Filament\Resources\FichasTecnicas\Schemas;

use App\Enums\Cozinha\EscopoIngrediente;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Support\InsumoFichaSelect;
use App\Models\FichaTecnica;
use App\Models\Produto;
use App\Models\SetorProducao;
use App\Services\Cozinha\FichaTecnicaMontador;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FichaTecnicaForm
{
    public const ALERGENOS = ['Glúten', 'Lactose', 'Ovo', 'Soja', 'Amendoim', 'Castanhas', 'Frutos do mar', 'Peixe', 'Gergelim', 'Mostarda'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::identificacao(),
            self::ingredientes(),
            self::modoDePreparo(),
            self::apresentacao(),
        ]);
    }

    private static function identificacao(): Section
    {
        return Section::make('Identificação')
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                Select::make('produto_id')
                    ->label('Produto')
                    ->options(fn (?FichaTecnica $record): array => Produto::query()
                        ->whereIn('produto_tipo', [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::INSUMO_PRODUZIDO->value])
                        ->where(fn ($q) => $q
                            ->whereDoesntHave('fichaTecnica', fn ($f) => $f->withTrashed())
                            ->when($record, fn ($qq) => $qq->orWhere('id', $record->produto_id)))
                        ->with('categoria:id,categoria_nome')
                        ->orderBy('produto_descricao')
                        ->get()
                        ->mapWithKeys(fn (Produto $p) => [$p->id => trim($p->produto_descricao.' — '.($p->categoria?->categoria_nome ?? ''), ' —')])
                        ->all())
                    ->default(fn (): ?int => request()->integer('produto') ?: null)
                    ->searchable()
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->helperText('Cada sabor de cada tamanho é um produto: Calabresa Grande e Calabresa Média têm fichas separadas.')
                    ->columnSpan(2),
                Toggle::make('ativo')
                    ->label('Ativa')
                    ->default(true)
                    ->inline(false),
                Text::make(fn (Get $get): string => self::resumoProduto($get('produto_id')))
                    ->color('gray')
                    ->columnSpanFull(),
                TextInput::make('tempo_preparo_min')
                    ->label('Tempo de preparo')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(600)
                    ->suffix('min'),
                TagsInput::make('alergenos')
                    ->label('Alérgenos')
                    ->suggestions(self::ALERGENOS)
                    ->placeholder('Digite e tecle Enter')
                    ->columnSpan(2),
                Textarea::make('pontos_criticos')
                    ->label('Pontos críticos')
                    ->placeholder('Ex.: queijo no ponto, sem queimar a borda')
                    ->rows(2)
                    ->columnSpanFull(),
                Textarea::make('descricao')
                    ->label('Observações gerais')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function ingredientes(): Section
    {
        return Section::make('Ingredientes')
            ->description('As mesmas linhas da ficha de custo do produto: mudar a quantidade aqui muda o CMV e a baixa de estoque. No meio a meio, a base aparece uma vez e cobertura/finalização entram pela fração de cada sabor.')
            ->columnSpanFull()
            ->hiddenOn('create')
            ->schema([
                Repeater::make('ingredientes')
                    ->hiddenLabel()
                    ->relationship()
                    ->orderColumn('fti_ordem')
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->defaultItems(0)
                    ->addActionLabel('Adicionar ingrediente')
                    ->itemLabel(fn (array $state): string => self::rotuloIngrediente($state))
                    ->columns(4)
                    ->schema([
                        InsumoFichaSelect::make(fn (Get $get): int => (int) $get('../../produto_id'))
                            ->columnSpan(2),
                        TextInput::make('fti_quantidade')
                            ->label('Quantidade')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0.0001)
                            ->required(),
                        TextInput::make('fti_unidade')
                            ->label('Unidade')
                            ->maxLength(20),
                        Select::make('fti_escopo')
                            ->label('Escopo')
                            ->options(EscopoIngrediente::class)
                            ->default(EscopoIngrediente::Cobertura)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->helperText('Base: massa, molho, queijo base.'),
                        TextInput::make('fti_opcional_ref')
                            ->label('Chave do "SEM"')
                            ->placeholder('Ex.: cebola')
                            ->maxLength(60)
                            ->helperText('Casa com "Sem cebola" na observação. Vazio: usa o nome do insumo.'),
                        TextInput::make('fti_percentual_perda')
                            ->label('Perda no preparo')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->default(0)
                            ->suffix('%'),
                        TextInput::make('fti_observacao')
                            ->label('Dica para a cozinha')
                            ->placeholder('Ex.: espalhar até a borda')
                            ->maxLength(255),
                        FileUpload::make('fti_foto_porcao')
                            ->label('Foto da porção')
                            ->disk(fn (): string => (string) config('cozinha.disco_fotos'))
                            ->directory('cozinha/porcoes')
                            ->visibility('public')
                            ->image()
                            ->imageResizeTargetWidth(600)
                            ->maxSize(4096)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function modoDePreparo(): Section
    {
        return Section::make('Modo de preparo')
            ->description('Cada tablet mostra só os passos do seu setor e os passos sem setor.')
            ->columnSpanFull()
            ->schema([
                Repeater::make('passos')
                    ->hiddenLabel()
                    ->relationship()
                    ->orderColumn('ordem')
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->defaultItems(0)
                    ->addActionLabel('Adicionar passo')
                    ->itemLabel(fn (array $state): ?string => $state['titulo'] ?? null)
                    ->columns(3)
                    ->schema([
                        TextInput::make('titulo')
                            ->label('Título')
                            ->placeholder('Ex.: Abrir a massa')
                            ->required()
                            ->maxLength(120)
                            ->columnSpan(2),
                        Select::make('setor_producao_id')
                            ->label('Setor')
                            ->options(fn (): array => SetorProducao::ordenados()->pluck('nome', 'id')->all())
                            ->placeholder('Todos os setores'),
                        Textarea::make('descricao')
                            ->label('Descrição')
                            ->rows(2)
                            ->columnSpanFull(),
                        TextInput::make('tempo_segundos')
                            ->label('Timer')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(7200)
                            ->suffix('s')
                            ->helperText('Ex.: 240 = 4 min. Gera o botão de timer no tablet.'),
                        TextInput::make('temperatura_alvo')
                            ->label('Temperatura')
                            ->placeholder('Ex.: 300 °C')
                            ->maxLength(20),
                        Toggle::make('ponto_critico')
                            ->label('Ponto crítico')
                            ->inline(false),
                        FileUpload::make('foto')
                            ->label('Foto')
                            ->disk(fn (): string => (string) config('cozinha.disco_fotos'))
                            ->directory('cozinha/passos')
                            ->visibility('public')
                            ->image()
                            ->imageResizeTargetWidth(1000)
                            ->maxSize(4096)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function apresentacao(): Section
    {
        return Section::make('Apresentação')
            ->columns(2)
            ->columnSpanFull()
            ->collapsible()
            ->schema([
                FileUpload::make('foto_apresentacao')
                    ->label('Foto do prato pronto')
                    ->disk(fn (): string => (string) config('cozinha.disco_fotos'))
                    ->directory('cozinha/apresentacao')
                    ->visibility('public')
                    ->image()
                    ->imageResizeTargetWidth(1200)
                    ->maxSize(6144),
                TextInput::make('video_url')
                    ->label('Vídeo do preparo')
                    ->url()
                    ->maxLength(255)
                    ->placeholder('https://...'),
            ]);
    }

    private static function resumoProduto(mixed $produtoId): string
    {
        $produto = filled($produtoId) ? Produto::with('categoria')->find($produtoId) : null;

        if ($produto === null) {
            return 'Escolha o produto. Os ingredientes já cadastrados na ficha de custo dele aparecem depois de salvar.';
        }

        return sprintf(
            'Tamanho/categoria: %s · Rendimento: %s %s · %d ingrediente(s) na ficha de custo',
            $produto->categoria?->categoria_nome ?? '—',
            rtrim(rtrim(number_format((float) ($produto->produto_ficha_rendimento ?? 1), 3, ',', ''), '0'), ','),
            $produto->produto_unidade_estoque ?? 'un',
            $produto->fichaItens()->count(),
        );
    }

    /** @param  array<string, mixed>  $state */
    private static function rotuloIngrediente(array $state): string
    {
        $nome = filled($state['fti_insumo_id'] ?? null) ? Produto::whereKey($state['fti_insumo_id'])->value('produto_descricao') : null;

        if ($nome === null) {
            return 'Novo ingrediente';
        }

        $escopo = $state['fti_escopo'] ?? null;
        $escopo = $escopo instanceof EscopoIngrediente ? $escopo : EscopoIngrediente::tryFrom((string) $escopo);
        $quantidade = filled($state['fti_quantidade'] ?? null)
            ? FichaTecnicaMontador::formatarQuantidade((float) $state['fti_quantidade'], $state['fti_unidade'] ?? null)
            : '';

        return trim("{$nome} — {$quantidade}".($escopo ? " ({$escopo->getLabel()})" : ''), ' —');
    }
}
