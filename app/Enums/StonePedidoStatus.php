<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StonePedidoStatus: string implements HasColor, HasLabel
{
    case Aguardando = 'aguardando';
    case PagoParcial = 'pago_parcial';
    case Pago = 'pago';
    case Estornado = 'estornado';
    case Cancelado = 'cancelado';
    case Falha = 'falha';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aguardando => 'Aguardando pagamento',
            self::PagoParcial => 'Pago parcial',
            self::Pago => 'Pago',
            self::Estornado => 'Estornado',
            self::Cancelado => 'Cancelado',
            self::Falha => 'Falha na criação',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aguardando => 'warning',
            self::PagoParcial => 'info',
            self::Pago => 'success',
            self::Estornado => 'danger',
            self::Cancelado => 'gray',
            self::Falha => 'danger',
        };
    }

    /** Status em que ainda se espera (ou pode chegar) pagamento na maquininha. */
    public function pendente(): bool
    {
        return $this === self::Aguardando || $this === self::PagoParcial;
    }
}
