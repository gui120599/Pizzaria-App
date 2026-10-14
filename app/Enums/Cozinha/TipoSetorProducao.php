<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/** Setor que produz itens ou o passe (expedição), que despacha o pedido inteiro. */
enum TipoSetorProducao: string implements HasColor, HasIcon, HasLabel
{
    case Producao = 'producao';
    case Expedicao = 'expedicao';

    public function getLabel(): string
    {
        return match ($this) {
            self::Producao => 'Produção',
            self::Expedicao => 'Expedição',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Producao => 'warning',
            self::Expedicao => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Producao => Heroicon::OutlinedFire,
            self::Expedicao => Heroicon::OutlinedArrowRightStartOnRectangle,
        };
    }
}
