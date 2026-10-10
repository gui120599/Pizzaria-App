<?php

namespace App\Enums;

use App\Support\DiasUteis;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;

/**
 * Prazo de recebimento configurado no aplicativo da maquininha: quando o
 * líquido da venda cai na conta.
 */
enum PrazoRecebimentoMaquininhaEnum: string implements HasLabel
{
    case NaHora = 'na_hora';
    case MesmoDia = 'mesmo_dia';
    case ProximoDiaUtil = 'proximo_dia_util';

    /** Vendas até este horário caem no mesmo dia; depois, no dia seguinte. */
    public const HORARIO_CORTE_MESMO_DIA = '22:00:00';

    public function getLabel(): string
    {
        return match ($this) {
            self::NaHora => 'Na hora',
            self::MesmoDia => 'Mesmo dia (vendas até 22h)',
            self::ProximoDiaUtil => 'Próximo dia útil',
        };
    }

    /** Dia em que o valor de uma venda feita em $venda cai na conta. */
    public function dataPrevista(CarbonInterface $venda): Carbon
    {
        $dia = Carbon::instance($venda)->startOfDay();

        return match ($this) {
            self::NaHora => $dia,
            self::MesmoDia => $venda->format('H:i:s') <= self::HORARIO_CORTE_MESMO_DIA ? $dia : $dia->addDay(),
            self::ProximoDiaUtil => DiasUteis::proximo($dia),
        };
    }
}
