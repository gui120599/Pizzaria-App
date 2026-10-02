<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Espelha mesas.mesa_tipo. Comanda numerada usa a mesma tabela e o mesmo
 * ciclo de sessão da mesa — só muda a apresentação.
 */
enum TipoMesaEnum: string implements HasLabel
{
    case MESA = 'MESA';
    case COMANDA = 'COMANDA';

    public function getLabel(): string
    {
        return match ($this) {
            self::MESA => 'Mesa',
            self::COMANDA => 'Comanda',
        };
    }
}
