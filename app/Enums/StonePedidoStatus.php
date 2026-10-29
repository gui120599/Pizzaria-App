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
    case Indeterminado = 'indeterminado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aguardando => 'Aguardando pagamento',
            self::PagoParcial => 'Pago parcial',
            self::Pago => 'Pago',
            self::Estornado => 'Estornado',
            self::Cancelado => 'Cancelado',
            self::Falha => 'Falha na criação',
            self::Indeterminado => 'Resultado desconhecido',
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
            self::Indeterminado => 'warning',
        };
    }

    /**
     * Status em que ainda se espera (ou pode chegar) pagamento na maquininha.
     * Indeterminado entra: o pedido pode existir na Stone, então ele ocupa o
     * saldo e a vaga até ser reconsultado.
     */
    public function pendente(): bool
    {
        return in_array($this, self::pendentes(), true);
    }

    /** @return list<self> */
    public static function pendentes(): array
    {
        return [self::Aguardando, self::PagoParcial, self::Indeterminado];
    }

    /** Valores gravados dos status pendentes, para consultas. */
    public static function valoresPendentes(): array
    {
        return array_map(fn (self $status): string => $status->value, self::pendentes());
    }
}
