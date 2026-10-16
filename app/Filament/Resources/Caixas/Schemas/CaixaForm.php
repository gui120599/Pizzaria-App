<?php

namespace App\Filament\Resources\Caixas\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CaixaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('caixa_nome')
                ->label('Nome')
                ->required()
                ->maxLength(255),

            Toggle::make('caixa_fechamento_obrigatorio')
                ->label('Fechamento obrigatório')
                ->helperText('Ligado: só abre uma nova sessão depois que o fechamento da última for confirmado.')
                ->default(true),
        ]);
    }
}
