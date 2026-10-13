<?php

namespace App\Enums\Checklist;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ExecucaoStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pendente = 'pendente';
    case EmAndamento = 'em_andamento';
    case Concluido = 'concluido';
    case ConcluidoComPendencias = 'concluido_com_pendencias';
    case Aprovado = 'aprovado';
    case Reprovado = 'reprovado';
    case NaoRealizado = 'nao_realizado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::EmAndamento => 'Em andamento',
            self::Concluido => 'Concluído',
            self::ConcluidoComPendencias => 'Concluído com pendências',
            self::Aprovado => 'Aprovado',
            self::Reprovado => 'Reprovado',
            self::NaoRealizado => 'Não realizado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendente => 'gray',
            self::EmAndamento => 'info',
            self::Concluido => 'success',
            self::ConcluidoComPendencias => 'warning',
            self::Aprovado => 'success',
            self::Reprovado => 'danger',
            self::NaoRealizado => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pendente => Heroicon::OutlinedClock,
            self::EmAndamento => Heroicon::OutlinedPlayCircle,
            self::Concluido => Heroicon::OutlinedCheckCircle,
            self::ConcluidoComPendencias => Heroicon::OutlinedExclamationTriangle,
            self::Aprovado => Heroicon::OutlinedCheckBadge,
            self::Reprovado => Heroicon::OutlinedXCircle,
            self::NaoRealizado => Heroicon::OutlinedNoSymbol,
        };
    }

    /** Ainda aceita respostas (reprovado só volta a aceitar depois de reaberto). */
    public function aceitaRespostas(): bool
    {
        return in_array($this, [self::Pendente, self::EmAndamento], true);
    }

    /** Finalizado pela equipe e aguardando (ou passível de) aprovação. */
    public function aguardaAprovacao(): bool
    {
        return in_array($this, [self::Concluido, self::ConcluidoComPendencias], true);
    }

    /** @return array<int, self> */
    public static function emAberto(): array
    {
        return [self::Pendente, self::EmAndamento];
    }
}
