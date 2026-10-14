<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasLabel;

enum MotivoCancelamentoProducao: string implements HasLabel
{
    case PedidoCancelado = 'pedido_cancelado';
    case ItemRemovido = 'item_removido';
    case QuantidadeReduzida = 'quantidade_reduzida';
    case Refeito = 'refeito';
    case Outro = 'outro';

    public function getLabel(): string
    {
        return match ($this) {
            self::PedidoCancelado => 'Pedido cancelado',
            self::ItemRemovido => 'Item removido',
            self::QuantidadeReduzida => 'Quantidade reduzida',
            self::Refeito => 'Refeito',
            self::Outro => 'Outro',
        };
    }
}
