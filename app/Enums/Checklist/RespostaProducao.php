<?php

namespace App\Enums\Checklist;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/** Respostas fixas do tipo "Fazer se precisar (conferir estoque)". */
enum RespostaProducao: string implements HasColor, HasIcon, HasLabel
{
    case Feito = 'feito';
    case TinhaEstoque = 'tinha_estoque';
    case NaoFeito = 'nao_feito';

    public function getLabel(): string
    {
        return match ($this) {
            self::Feito => 'Feito',
            self::TinhaEstoque => 'Tinha estoque',
            self::NaoFeito => 'Não feito',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Feito => 'success',
            self::TinhaEstoque => 'info',
            self::NaoFeito => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Feito => Heroicon::OutlinedCheck,
            self::TinhaEstoque => Heroicon::OutlinedArchiveBox,
            self::NaoFeito => Heroicon::OutlinedXMark,
        };
    }

    public function conforme(): bool
    {
        return $this !== self::NaoFeito;
    }
}
