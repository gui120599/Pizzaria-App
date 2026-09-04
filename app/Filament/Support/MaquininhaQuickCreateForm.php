<?php

namespace App\Filament\Support;

use App\Enums\OperadoraMaquininha;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * Campos de cadastro de Maquininha, reaproveitados no createOptionForm inline
 * dos repeaters de abertura/fechamento de caixa E no MaquininhaResource — uma
 * única fonte, evita os dois repeaters divergirem de novo.
 */
final class MaquininhaQuickCreateForm
{
    public static function schema(): array
    {
        return [
            TextInput::make('nome')
                ->label('Nome')
                ->required()
                ->maxLength(255),
            Select::make('operadora')
                ->label('Operadora')
                ->options(OperadoraMaquininha::class)
                ->required(),
            TextInput::make('numero_serie')
                ->label('Número de série')
                ->maxLength(255),
        ];
    }
}
