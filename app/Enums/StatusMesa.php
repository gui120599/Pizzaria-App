<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Espelha os valores string crus da coluna mesa_status ('LIBERADA'/'OCUPADA'/'INATIVA').
 * Usado só para apresentação (labels/cores/opções) no Filament — o model Mesa NÃO
 * casta essa coluna pra este enum, pois o Blade legado, o SessaoMesaController e o
 * OperarVenda ainda comparam a coluna como string crua.
 */
enum StatusMesa: string implements HasColor, HasLabel
{
    case Liberada = 'LIBERADA';
    case Ocupada = 'OCUPADA';
    case Inativa = 'INATIVA';

    public function getLabel(): string
    {
        return match ($this) {
            self::Liberada => 'Liberada',
            self::Ocupada => 'Ocupada',
            self::Inativa => 'Inativa',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Liberada => 'success',
            self::Ocupada => 'danger',
            self::Inativa => 'gray',
        };
    }
}
