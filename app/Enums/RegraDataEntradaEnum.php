<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Qual data de entrada no estoque é sugerida para as compras do fornecedor. */
enum RegraDataEntradaEnum: string implements HasLabel
{
    case Hoje = 'hoje';
    case Emissao = 'emissao';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hoje => 'Dia da importação/confirmação',
            self::Emissao => 'Data de emissão da nota',
        };
    }
}
