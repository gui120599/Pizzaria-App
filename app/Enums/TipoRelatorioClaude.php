<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** Tipo de relatório gravado pelas rotinas do Claude (G2). */
enum TipoRelatorioClaude: string implements HasColor, HasIcon, HasLabel
{
    case ResumoDia = 'resumo_dia';
    case SugestaoCompras = 'sugestao_compras';
    case Anomalias = 'anomalias';
    case Outro = 'outro';

    public function getLabel(): string
    {
        return match ($this) {
            self::ResumoDia => 'Resumo do dia',
            self::SugestaoCompras => 'Sugestão de compras',
            self::Anomalias => 'Anomalias',
            self::Outro => 'Outro',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ResumoDia => 'info',
            self::SugestaoCompras => 'success',
            self::Anomalias => 'danger',
            self::Outro => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::ResumoDia => 'heroicon-o-sun',
            self::SugestaoCompras => 'heroicon-o-shopping-cart',
            self::Anomalias => 'heroicon-o-exclamation-triangle',
            self::Outro => 'heroicon-o-document-text',
        };
    }
}
