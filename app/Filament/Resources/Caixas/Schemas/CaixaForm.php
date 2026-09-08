<?php

namespace App\Filament\Resources\Caixas\Schemas;

use Filament\Forms\Components\TextInput;
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
        ]);
    }
}
