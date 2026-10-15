<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Rótulo sugerido pela paridade das marcações do dia. É só exibição: a apuração usa a ordem cronológica.
 */
enum TipoMarcacao: string implements HasColor, HasIcon, HasLabel
{
    case Entrada = 'entrada';
    case SaidaIntervalo = 'saida_intervalo';
    case RetornoIntervalo = 'retorno_intervalo';
    case Saida = 'saida';

    public function getLabel(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::SaidaIntervalo => 'Saída para intervalo',
            self::RetornoIntervalo => 'Retorno do intervalo',
            self::Saida => 'Saída',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Entrada => 'success',
            self::SaidaIntervalo => 'warning',
            self::RetornoIntervalo => 'info',
            self::Saida => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Entrada => Heroicon::OutlinedArrowRightEndOnRectangle,
            self::SaidaIntervalo => Heroicon::OutlinedPause,
            self::RetornoIntervalo => Heroicon::OutlinedPlay,
            self::Saida => Heroicon::OutlinedArrowLeftStartOnRectangle,
        };
    }

    /** Sugestão pela quantidade de marcações efetivas já feitas no dia (0 = primeira). */
    public static function sugerido(int $marcacoesNoDia): self
    {
        if ($marcacoesNoDia === 0) {
            return self::Entrada;
        }

        if ($marcacoesNoDia % 2 === 1) {
            return $marcacoesNoDia === 1 ? self::SaidaIntervalo : self::Saida;
        }

        return self::RetornoIntervalo;
    }
}
