<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** A partir de qual data o prazo de pagamento do fornecedor é contado. */
enum BaseVencimentoEnum: string implements HasLabel
{
    case Emissao = 'emissao';
    case Entrada = 'entrada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Emissao => 'Data de emissão da nota',
            self::Entrada => 'Data de entrada no estoque',
        };
    }
}
