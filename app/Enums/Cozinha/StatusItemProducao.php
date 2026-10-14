<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Máquina de estados do item de produção (spec §3.3). Só a última etapa de
 * uma rota chega a `expedido`; `pronto` numa etapa intermediária é terminal
 * para aquela linha e libera a seguinte.
 */
enum StatusItemProducao: string implements HasColor, HasIcon, HasLabel
{
    case Aguardando = 'aguardando';
    case NaFila = 'na_fila';
    case EmPreparo = 'em_preparo';
    case Pronto = 'pronto';
    case Expedido = 'expedido';
    case Cancelado = 'cancelado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aguardando => 'Aguardando',
            self::NaFila => 'Na fila',
            self::EmPreparo => 'Em preparo',
            self::Pronto => 'Pronto',
            self::Expedido => 'Expedido',
            self::Cancelado => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aguardando => 'gray',
            self::NaFila => 'info',
            self::EmPreparo => 'warning',
            self::Pronto, self::Expedido => 'success',
            self::Cancelado => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Aguardando => Heroicon::OutlinedPauseCircle,
            self::NaFila => Heroicon::OutlinedQueueList,
            self::EmPreparo => Heroicon::OutlinedFire,
            self::Pronto => Heroicon::OutlinedCheckCircle,
            self::Expedido => Heroicon::OutlinedArrowRightStartOnRectangle,
            self::Cancelado => Heroicon::OutlinedXCircle,
        };
    }

    /**
     * Destinos válidos a partir deste status. `pronto → em_preparo` é o
     * desfazer/recall; `na_fila → pronto` só quando o setor permite pular o
     * início (checado no ProducaoService, não aqui).
     *
     * @return list<self>
     */
    public function destinosPermitidos(): array
    {
        return match ($this) {
            self::Aguardando => [self::NaFila, self::Cancelado],
            self::NaFila => [self::EmPreparo, self::Pronto, self::Cancelado],
            self::EmPreparo => [self::NaFila, self::Pronto, self::Cancelado],
            self::Pronto => [self::EmPreparo, self::Expedido, self::Cancelado],
            self::Expedido, self::Cancelado => [],
        };
    }

    public function podeTransitarPara(self $destino): bool
    {
        return in_array($destino, $this->destinosPermitidos(), true);
    }

    public function ehTerminal(): bool
    {
        return $this === self::Expedido || $this === self::Cancelado;
    }

    /** Ocupa a estação: conta no all-day e nos contadores do topo. */
    public function ehAtivoNaEstacao(): bool
    {
        return $this === self::NaFila || $this === self::EmPreparo;
    }

    /** @return list<string> */
    public static function valoresAtivosNaEstacao(): array
    {
        return [self::NaFila->value, self::EmPreparo->value];
    }
}
