<?php

namespace App\Filament\Resources\DispositivosCozinha\Schemas;

use App\Enums\Cozinha\ModoExibicaoCozinha;
use App\Enums\Cozinha\TamanhoFonteCozinha;
use App\Models\SetorProducao;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class DispositivoCozinhaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->placeholder('Ex.: Tablet do forno')
                        ->required()
                        ->maxLength(60),
                    Select::make('setores')
                        ->label('Setores')
                        ->options(fn (): array => SetorProducao::ativos()->ordenados()->pluck('nome', 'id')->all())
                        ->multiple()
                        ->required()
                        // JSON de inteiros: o scope doSetor() usa JSON_CONTAINS com número.
                        ->dehydrateStateUsing(fn (?array $state): array => array_values(array_map('intval', $state ?? [])))
                        ->helperText('O tablet mostra só os itens destes setores.'),
                    Select::make('modo_exibicao')
                        ->label('Modo de exibição')
                        ->options(ModoExibicaoCozinha::class)
                        ->default(ModoExibicaoCozinha::Itens)
                        ->selectablePlaceholder(false)
                        ->required(),
                    Select::make('tamanho_fonte')
                        ->label('Tamanho da fonte')
                        ->options(TamanhoFonteCozinha::class)
                        ->default(TamanhoFonteCozinha::Normal)
                        ->selectablePlaceholder(false)
                        ->required(),
                    Toggle::make('som_ativo')
                        ->label('Som de item novo')
                        ->default(true),
                    Toggle::make('ativo')
                        ->label('Ativo')
                        ->default(true)
                        ->helperText('Desligado, o tablet para de funcionar até ser pareado de novo.'),
                ]),
        ]);
    }
}
