<?php

namespace App\Enums\Compras;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatusPendencia: string implements HasColor, HasLabel
{
    case Aberta = 'aberta';
    case Sugerida = 'sugerida';
    case Resolvida = 'resolvida';
    case Descartada = 'descartada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::Sugerida => 'Sugestão aguardando aprovação',
            self::Resolvida => 'Resolvida',
            self::Descartada => 'Descartada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aberta => 'warning',
            self::Sugerida => 'info',
            self::Resolvida => 'success',
            self::Descartada => 'gray',
        };
    }

    /** @return list<self> */
    public static function emAberto(): array
    {
        return [self::Aberta, self::Sugerida];
    }
}
