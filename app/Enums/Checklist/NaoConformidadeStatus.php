<?php

namespace App\Enums\Checklist;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NaoConformidadeStatus: string implements HasColor, HasLabel
{
    case Aberta = 'aberta';
    case EmAndamento = 'em_andamento';
    case Resolvida = 'resolvida';
    case Cancelada = 'cancelada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::EmAndamento => 'Em andamento',
            self::Resolvida => 'Resolvida',
            self::Cancelada => 'Cancelada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aberta => 'danger',
            self::EmAndamento => 'warning',
            self::Resolvida => 'success',
            self::Cancelada => 'gray',
        };
    }

    /** @return array<int, self> */
    public static function pendentes(): array
    {
        return [self::Aberta, self::EmAndamento];
    }
}
