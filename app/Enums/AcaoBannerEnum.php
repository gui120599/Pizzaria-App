<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** O que acontece quando o cliente toca no banner do carrossel do cardápio. */
enum AcaoBannerEnum: string implements HasLabel
{
    case NENHUMA = 'NENHUMA';
    case CATEGORIA = 'CATEGORIA';
    case PRODUTO = 'PRODUTO';
    case LINK = 'LINK';

    public function getLabel(): string
    {
        return match ($this) {
            self::NENHUMA => 'Só a imagem (sem ação)',
            self::CATEGORIA => 'Ir para uma categoria do cardápio',
            self::PRODUTO => 'Abrir um produto',
            self::LINK => 'Abrir um link',
        };
    }
}
