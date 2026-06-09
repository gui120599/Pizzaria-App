<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

class PedidoItensField extends Field
{
    protected string $view = 'filament.forms.components.pedido-itens-field';

    public function getDefaultState(): mixed
    {
        return [];
    }
}
