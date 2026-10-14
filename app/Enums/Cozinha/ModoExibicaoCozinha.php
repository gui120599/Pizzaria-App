<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasLabel;

/** Como a Estação do tablet agrupa os cards: por item ou por pedido. */
enum ModoExibicaoCozinha: string implements HasLabel
{
    case Itens = 'itens';
    case Pedidos = 'pedidos';

    public function getLabel(): string
    {
        return match ($this) {
            self::Itens => 'Por item',
            self::Pedidos => 'Por pedido',
        };
    }
}
