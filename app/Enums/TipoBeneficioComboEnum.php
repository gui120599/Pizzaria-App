<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Como a promoção combo dá o benefício. Hoje só o preço fechado do conjunto
 * ("2 por R$ 87,99"); novos tipos entram aqui e no PromocaoComboMotor.
 */
enum TipoBeneficioComboEnum: string implements HasLabel
{
    case PRECO_FIXO = 'PRECO_FIXO';

    public function getLabel(): string
    {
        return match ($this) {
            self::PRECO_FIXO => 'Preço fechado do conjunto',
        };
    }
}
