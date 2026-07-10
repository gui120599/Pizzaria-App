<?php

namespace App\Filament\Resources\PlanoDespesas\Schemas;

use App\Enums\Comportamento;
use App\Enums\Periodicidade;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlanoDespesaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conta do plano de despesas')
                ->columns(2)
                ->schema([
                    TextInput::make('codigo')
                        ->label('Código')
                        ->maxLength(255),
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    Select::make('pai_id')
                        ->label('Grupo / conta pai')
                        ->relationship('pai', 'nome')
                        ->searchable()
                        ->preload(),
                    Select::make('comportamento')
                        ->label('Comportamento')
                        ->options(Comportamento::class)
                        ->required(),
                    Select::make('periodicidade')
                        ->label('Periodicidade')
                        ->options(Periodicidade::class)
                        ->default(Periodicidade::Eventual),
                ]),
        ]);
    }
}
