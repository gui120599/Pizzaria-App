<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Status derivado da promoção relâmpago. Não existe coluna no banco: é sempre
 * calculado a partir de promocao_ativa, da janela de vigência e do saldo.
 */
enum PromocaoStatusEnum: string implements HasColor, HasLabel
{
    case Inativa = 'inativa';
    case Agendada = 'agendada';
    case Ativa = 'ativa';
    case Esgotada = 'esgotada';
    case Encerrada = 'encerrada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inativa => 'Inativa',
            self::Agendada => 'Agendada',
            self::Ativa => 'Ativa',
            self::Esgotada => 'Esgotada',
            self::Encerrada => 'Encerrada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Inativa => 'gray',
            self::Agendada => 'info',
            self::Ativa => 'success',
            self::Esgotada => 'danger',
            self::Encerrada => 'gray',
        };
    }
}
