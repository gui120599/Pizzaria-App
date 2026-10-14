<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Status de produção do pedido, derivado dos itens (spec §3.4) e guardado em
 * `pedidos.pedido_producao_status` só como cache para as listagens. Quem
 * traduz para o `pedido_status` real é o PedidoStatusGateway.
 */
enum StatusProducaoPedido: string implements HasColor, HasIcon, HasLabel
{
    case SemProducao = 'sem_producao';
    case Aguardando = 'aguardando';
    case EmProducao = 'em_producao';
    case Pronto = 'pronto';
    case Expedido = 'expedido';
    case Cancelado = 'cancelado';

    /** Ainda tem item na fila ou em preparo (dá para priorizar). */
    public function aindaNaCozinha(): bool
    {
        return $this === self::Aguardando || $this === self::EmProducao;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::SemProducao => 'Sem produção',
            self::Aguardando => 'Aguardando cozinha',
            self::EmProducao => 'Em produção',
            self::Pronto => 'Pronto',
            self::Expedido => 'Expedido',
            self::Cancelado => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SemProducao => 'gray',
            self::Aguardando => 'info',
            self::EmProducao => 'warning',
            self::Pronto, self::Expedido => 'success',
            self::Cancelado => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::SemProducao => Heroicon::OutlinedMinusCircle,
            self::Aguardando => Heroicon::OutlinedQueueList,
            self::EmProducao => Heroicon::OutlinedFire,
            self::Pronto => Heroicon::OutlinedCheckCircle,
            self::Expedido => Heroicon::OutlinedArrowRightStartOnRectangle,
            self::Cancelado => Heroicon::OutlinedXCircle,
        };
    }
}
