<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Espelha os valores string crus da coluna sessao_mesa_status
 * ('ABERTA'/'FECHADA'/'FINALIZADA'/'CANCELADA'). Usado só para apresentação
 * (labels/cores/opções) no Filament — o model SessaoMesa NÃO casta essa coluna
 * pra este enum, pois SessaoMesaController e OperarVenda ainda comparam a coluna
 * como string crua.
 */
enum StatusSessaoMesa: string implements HasColor, HasLabel
{
    case Aberta = 'ABERTA';
    case Fechada = 'FECHADA';
    case Finalizada = 'FINALIZADA';
    case Cancelada = 'CANCELADA';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::Fechada => 'Fechada',
            self::Finalizada => 'Finalizada',
            self::Cancelada => 'Cancelada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aberta => 'info',
            self::Fechada => 'warning',
            self::Finalizada => 'success',
            self::Cancelada => 'danger',
        };
    }
}
