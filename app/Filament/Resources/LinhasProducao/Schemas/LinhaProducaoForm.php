<?php

namespace App\Filament\Resources\LinhasProducao\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LinhaProducaoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Linha de produção')
                ->schema([
                    TextInput::make('linha_nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Select::make('categorias')
                        ->label('Categorias')
                        ->relationship('categorias', 'categoria_nome')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->required()
                        ->helperText('Pedidos com pelo menos um item de uma dessas categorias (ou de uma subcategoria delas) aparecem no Painel de Pedidos quando esta linha estiver selecionada no filtro.'),
                ]),
        ]);
    }
}
