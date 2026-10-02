<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Como a pizza de vários sabores é precificada (categorias.categoria_regra_preco_sabores).
 * Vale para todos os canais: cardápio online, PDV, atendente e garçom.
 */
enum RegraPrecoSaboresEnum: string implements HasLabel
{
    case MEDIA = 'MEDIA';
    case MAIOR = 'MAIOR';

    public function getLabel(): string
    {
        return match ($this) {
            self::MEDIA => 'Média dos sabores',
            self::MAIOR => 'Maior valor entre os sabores',
        };
    }
}
