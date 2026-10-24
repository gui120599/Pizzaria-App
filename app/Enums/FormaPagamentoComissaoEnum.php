<?php

namespace App\Enums;

enum FormaPagamentoComissaoEnum: string
{
    case PIX = 'pix';
    case CREDITO_PIZZA = 'credito_pizza';

    public function label(): string
    {
        return match ($this) {
            self::PIX => 'Pix',
            self::CREDITO_PIZZA => 'Crédito para Pizza',
        };
    }
}
