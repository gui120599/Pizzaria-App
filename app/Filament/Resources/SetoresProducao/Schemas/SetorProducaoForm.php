<?php

namespace App\Filament\Resources\SetoresProducao\Schemas;

use App\Enums\Cozinha\TipoSetorProducao;
use App\Models\SetorProducao;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SetorProducaoForm
{
    /** Ícones oferecidos para o cabeçalho do setor no tablet. */
    public const ICONES = [
        'heroicon-o-fire' => 'Fogo (forno, pizzaria)',
        'heroicon-o-cake' => 'Bolo (sobremesas)',
        'heroicon-o-beaker' => 'Copo (bar, bebidas)',
        'heroicon-o-sparkles' => 'Brilho (finalização)',
        'heroicon-o-squares-2x2' => 'Grade (montagem)',
        'heroicon-o-shopping-bag' => 'Sacola (embalagem)',
        'heroicon-o-arrow-right-start-on-rectangle' => 'Saída (expedição)',
        'heroicon-o-clipboard-document-list' => 'Prancheta (geral)',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->placeholder('Ex.: Pizzaria, Cozinha, Bar')
                        ->required()
                        ->maxLength(60)
                        ->unique(ignoreRecord: true)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state, string $operation): void {
                            if ($operation === 'create' || blank($get('slug'))) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->label('Identificador')
                        ->helperText('Usado em links e na configuração (ex.: setor padrão).')
                        ->required()
                        ->maxLength(60)
                        ->alphaDash()
                        ->unique(ignoreRecord: true),
                    Select::make('tipo')
                        ->label('Tipo')
                        ->options(TipoSetorProducao::class)
                        ->default(TipoSetorProducao::Producao)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->helperText('Expedição é o passe: confere o pedido inteiro e despacha.')
                        ->rules([
                            fn (?SetorProducao $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $tipo = $value instanceof TipoSetorProducao ? $value : TipoSetorProducao::tryFrom((string) $value);

                                if ($tipo !== TipoSetorProducao::Expedicao) {
                                    return;
                                }

                                $outro = SetorProducao::ativos()
                                    ->where('tipo', TipoSetorProducao::Expedicao)
                                    ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                    ->value('nome');

                                if ($outro !== null) {
                                    $fail("Já existe um setor de expedição ativo ({$outro}). Desative-o antes.");
                                }
                            },
                        ]),
                    ColorPicker::make('cor')
                        ->label('Cor')
                        ->default('#f97316')
                        ->required(),
                    Select::make('icone')
                        ->label('Ícone')
                        ->options(self::ICONES)
                        ->native(false),
                    Toggle::make('ativo')
                        ->label('Ativo')
                        ->default(true)
                        ->inline(false),
                ]),

            Section::make('Tempos')
                ->description('O timer do card fica amarelo no alerta e vermelho no alvo. O tempo previsto na rota do produto, quando preenchido, substitui o alvo.')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('tempo_alerta_min')
                        ->label('Alerta (amarelo)')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(240)
                        ->suffix('min')
                        ->default(10)
                        ->required(),
                    TextInput::make('tempo_alvo_min')
                        ->label('Alvo (vermelho)')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(240)
                        ->suffix('min')
                        ->default(15)
                        ->required()
                        ->gte('tempo_alerta_min')
                        ->validationMessages(['gte' => 'O alvo não pode ser menor que o alerta.']),
                ]),

            Section::make('Comportamento no tablet')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('explode_unidades')
                        ->label('Um card por unidade')
                        ->helperText('Ligado: 2 pizzas = 2 cards. Desligado: 1 card com a quantidade (ex.: 6 latas).')
                        ->default(true),
                    Toggle::make('permite_pular_inicio')
                        ->label('Pode marcar pronto sem iniciar')
                        ->helperText('Para itens rápidos (bebidas) em que o "Iniciar" só atrapalha.'),
                    Toggle::make('exige_operador')
                        ->label('Exige operador identificado')
                        ->helperText('Quem produziu fica gravado no item (relatório por operador).')
                        ->default(true),
                    Toggle::make('exibir_ficha_automatica')
                        ->label('Abrir a ficha técnica ao iniciar'),
                    Toggle::make('som_novo_item')
                        ->label('Som quando chega item novo')
                        ->default(true),
                ]),
        ]);
    }
}
