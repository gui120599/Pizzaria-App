<?php

namespace App\Enums\Gestao;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatusAcaoClaude: string implements HasColor, HasLabel
{
    case Aberta = 'aberta';
    case Confirmada = 'confirmada';
    case Descartada = 'descartada';
    case Expirada = 'expirada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aberta => 'Aguardando confirmação',
            self::Confirmada => 'Confirmada',
            self::Descartada => 'Descartada',
            self::Expirada => 'Expirada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aberta => 'warning',
            self::Confirmada => 'success',
            self::Descartada, self::Expirada => 'gray',
        };
    }
}
