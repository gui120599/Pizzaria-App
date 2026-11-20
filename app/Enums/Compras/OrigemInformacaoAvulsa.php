<?php

namespace App\Enums\Compras;

use Filament\Support\Contracts\HasLabel;

/** De onde o Claude tirou os dados da compra avulsa. */
enum OrigemInformacaoAvulsa: string implements HasLabel
{
    case Imagem = 'imagem';
    case Mensagem = 'mensagem';
    case Misto = 'misto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Imagem => 'Foto do cupom/recibo',
            self::Mensagem => 'Mensagem',
            self::Misto => 'Foto e mensagem',
        };
    }
}
