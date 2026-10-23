<?php

namespace App\Filament\Resources\Caixas\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

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

            Money::make('caixa_troco_padrao')
                ->label('Troco padrão')
                ->helperText('Quanto de dinheiro fica na gaveta para o próximo turno. É a sugestão do campo "Troco que fica no caixa" no fechamento.'),
        ]);
    }
}
