<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de transação na maquininha, que define a taxa da adquirente. Vem de
 * OpcoesPagamento::tipoMaquininha() (pelo opcaopag_desc_nfe).
 */
enum TipoPagamentoMaquininhaEnum: string implements HasLabel
{
    case Debito = 'debito';
    case Credito = 'credito';
    case Pix = 'pix';

    public function getLabel(): string
    {
        return match ($this) {
            self::Debito => 'Débito',
            self::Credito => 'Crédito',
            self::Pix => 'Pix',
        };
    }
}
