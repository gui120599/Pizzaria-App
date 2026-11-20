<?php

namespace App\Enums\Compras;

use Filament\Support\Contracts\HasLabel;

/** Compra avulsa: paga na hora (baixa na confirmação) ou a pagar (fiado, com vencimento). */
enum SituacaoPagamentoAvulsa: string implements HasLabel
{
    case Pago = 'pago';
    case APagar = 'a_pagar';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pago => 'Pago',
            self::APagar => 'A pagar',
        };
    }
}
