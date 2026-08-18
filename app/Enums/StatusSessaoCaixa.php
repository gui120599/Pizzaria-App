<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Espelha os valores string crus da coluna sessaocaixa_status ('ABERTA'/'FECHADA').
 * Usado só para apresentação (labels/cores/opções) no Filament — o model SessaoCaixa
 * NÃO casta essa coluna pra este enum, pois o Blade legado ainda compara a coluna
 * como string crua ($sessaoCaixa->sessaocaixa_status === 'ABERTA').
 */
enum StatusSessaoCaixa: string implements HasColor, HasLabel
{
    case Aberta = 'ABERTA';
    case Fechada = 'FECHADA';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::Fechada => 'Fechada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aberta => 'info',
            self::Fechada => 'gray',
        };
    }
}
