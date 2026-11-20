<?php

namespace App\Filament\Resources\BannersCardapio\Schemas;

use App\Enums\AcaoBannerEnum;
use App\Models\Produto;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class BannerCardapioForm
{
    /**
     * Sem editor nem redimensionamento no navegador: os dois recompõem a
     * imagem num canvas e o GIF perderia a animação. A arte já sobe no
     * tamanho certo; o cardápio recorta pelo centro (object-cover).
     */
    private const TIPOS = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Imagens')
                ->description('JPG, PNG, WebP ou GIF animado, até 5 MB. Prefira arquivos leves: o banner é a primeira coisa que o cliente baixa ao abrir o cardápio.')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('banner_titulo')
                        ->label('Título')
                        ->helperText('Uso interno e texto alternativo da imagem (leitores de tela). Ex.: "Sextou — hoje a partir das 18h".')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    FileUpload::make('banner_imagem')
                        ->label('Imagem para computador')
                        ->helperText('1680 × 640 px (faixa larga, 21:8).')
                        ->disk('public')
                        ->directory('banners_cardapio')
                        ->visibility('public')
                        ->image()
                        ->acceptedFileTypes(self::TIPOS)
                        ->maxSize(5120)
                        ->required(),
                    FileUpload::make('banner_imagem_mobile')
                        ->label('Imagem para celular (opcional)')
                        ->helperText('1080 × 1080 px (quadrada, 1:1). Sem ela, o celular mostra a de computador.')
                        ->disk('public')
                        ->directory('banners_cardapio')
                        ->visibility('public')
                        ->image()
                        ->acceptedFileTypes(self::TIPOS)
                        ->maxSize(5120),
                ]),

            Section::make('Ao tocar no banner')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('banner_acao')
                        ->label('Ação')
                        ->options(AcaoBannerEnum::class)
                        ->default(AcaoBannerEnum::NENHUMA)
                        ->selectablePlaceholder(false)
                        ->live()
                        ->required(),
                    Select::make('banner_categoria_id')
                        ->label('Categoria')
                        ->relationship('categoria', 'categoria_nome', fn (Builder $query) => $query
                            ->whereNull('categoria_pai_id')
                            ->where('categoria_cardapio', true)
                            ->orderBy('categoria_ordem'))
                        ->helperText('O cardápio rola até a seção da categoria.')
                        ->preload()
                        ->visible(fn (Get $get): bool => self::acao($get) === AcaoBannerEnum::CATEGORIA)
                        ->required(fn (Get $get): bool => self::acao($get) === AcaoBannerEnum::CATEGORIA),
                    Select::make('banner_produto_id')
                        ->label('Produto')
                        ->relationship('produto', 'produto_descricao', fn (Builder $query) => $query->visivelCardapio())
                        ->getOptionLabelFromRecordUsing(fn (Produto $record): string => $record->nomeExibicao())
                        ->helperText('Abre o detalhe do produto, com o botão de adicionar.')
                        ->searchable(['produto_descricao'])
                        ->visible(fn (Get $get): bool => self::acao($get) === AcaoBannerEnum::PRODUTO)
                        ->required(fn (Get $get): bool => self::acao($get) === AcaoBannerEnum::PRODUTO),
                    TextInput::make('banner_url')
                        ->label('Link')
                        ->placeholder('https://www.instagram.com/...')
                        ->helperText('Abre em outra aba. Só endereços http:// ou https://.')
                        ->url()
                        ->regex('/^https?:\/\//i')
                        ->maxLength(500)
                        ->visible(fn (Get $get): bool => self::acao($get) === AcaoBannerEnum::LINK)
                        ->required(fn (Get $get): bool => self::acao($get) === AcaoBannerEnum::LINK),
                ]),

            Section::make('Onde e quando aparece')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('banner_ativo')
                        ->label('Ligado')
                        ->helperText('Desligue para tirar do ar sem apagar.')
                        ->default(true),
                    Toggle::make('banner_recorrente')
                        ->label('Repetir em dias da semana')
                        ->helperText('Ex.: toda sexta, das 18h às 23h59. Desligado, vale a janela de datas (ou para sempre).')
                        ->live(),
                    Toggle::make('banner_exibir_delivery')
                        ->label('Mostrar no cardápio de delivery')
                        ->default(true),
                    Toggle::make('banner_exibir_mesa')
                        ->label('Mostrar no cardápio da mesa (QR)')
                        ->default(true),
                    DateTimePicker::make('banner_inicio')
                        ->label(fn (Get $get): string => $get('banner_recorrente') ? 'A partir de (opcional)' : 'Início (opcional)')
                        ->helperText('Em branco = já está valendo.')
                        ->seconds(false),
                    DateTimePicker::make('banner_fim')
                        ->label('Fim (opcional)')
                        ->helperText('Em branco = até alguém desligar.')
                        ->seconds(false)
                        ->after('banner_inicio')
                        ->visible(fn (Get $get): bool => ! $get('banner_recorrente')),
                    DatePicker::make('banner_data_final_recorrencia')
                        ->label('Repetir até (opcional)')
                        ->helperText('Em branco = para sempre.')
                        ->visible(fn (Get $get): bool => (bool) $get('banner_recorrente')),
                    CheckboxList::make('banner_dias_semana')
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
                        ->visible(fn (Get $get): bool => (bool) $get('banner_recorrente'))
                        ->columnSpanFull(),
                    TimePicker::make('banner_hora_inicio')
                        ->label('Horário de início')
                        ->seconds(false)
                        ->visible(fn (Get $get): bool => (bool) $get('banner_recorrente')),
                    TimePicker::make('banner_hora_fim')
                        ->label('Horário de fim')
                        ->seconds(false)
                        ->helperText('Em branco nos dois = o dia inteiro.')
                        ->visible(fn (Get $get): bool => (bool) $get('banner_recorrente')),
                ]),
        ]);
    }

    private static function acao(Get $get): ?AcaoBannerEnum
    {
        $acao = $get('banner_acao');

        return $acao instanceof AcaoBannerEnum ? $acao : AcaoBannerEnum::tryFrom((string) $acao);
    }
}
