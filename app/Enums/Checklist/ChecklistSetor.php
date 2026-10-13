<?php

namespace App\Enums\Checklist;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ChecklistSetor: string implements HasColor, HasIcon, HasLabel
{
    case Salao = 'salao';
    case Cozinha = 'cozinha';
    case Caixa = 'caixa';
    case Delivery = 'delivery';
    case Geral = 'geral';

    public function getLabel(): string
    {
        return match ($this) {
            self::Salao => 'Salão',
            self::Cozinha => 'Cozinha',
            self::Caixa => 'Caixa',
            self::Delivery => 'Delivery',
            self::Geral => 'Geral',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Salao => 'info',
            self::Cozinha => 'danger',
            self::Caixa => 'success',
            self::Delivery => 'warning',
            self::Geral => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Salao => Heroicon::OutlinedUserGroup,
            self::Cozinha => Heroicon::OutlinedFire,
            self::Caixa => Heroicon::OutlinedBanknotes,
            self::Delivery => Heroicon::OutlinedTruck,
            self::Geral => Heroicon::OutlinedBuildingStorefront,
        };
    }
}
