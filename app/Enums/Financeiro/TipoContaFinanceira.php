<?php

namespace App\Enums\Financeiro;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Tipo da conta financeira. Só uma caixa_pdv pode existir: é a gaveta das sessões de caixa.
 */
enum TipoContaFinanceira: string implements HasColor, HasIcon, HasLabel
{
    case CaixaPdv = 'caixa_pdv';
    case CaixaInterno = 'caixa_interno';
    case Banco = 'banco';
    case ContaDigital = 'conta_digital';
    case Outro = 'outro';

    public function getLabel(): string
    {
        return match ($this) {
            self::CaixaPdv => 'Caixa PDV',
            self::CaixaInterno => 'Caixa interno / cofre',
            self::Banco => 'Banco',
            self::ContaDigital => 'Conta digital',
            self::Outro => 'Outro',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CaixaPdv => 'warning',
            self::CaixaInterno => 'gray',
            self::Banco => 'primary',
            self::ContaDigital => 'info',
            self::Outro => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::CaixaPdv => Heroicon::OutlinedComputerDesktop,
            self::CaixaInterno => Heroicon::OutlinedArchiveBox,
            self::Banco => Heroicon::OutlinedBuildingLibrary,
            self::ContaDigital => Heroicon::OutlinedDevicePhoneMobile,
            self::Outro => Heroicon::OutlinedWallet,
        };
    }

    /** Dados bancários/PIX só fazem sentido nestes tipos. */
    public function temDadosBancarios(): bool
    {
        return in_array($this, [self::Banco, self::ContaDigital], true);
    }
}
