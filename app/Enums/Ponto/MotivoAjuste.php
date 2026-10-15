<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Motivo do ajuste (solicitação do colaborador ou correção do gestor).
 */
enum MotivoAjuste: string implements HasColor, HasLabel
{
    case Esquecimento = 'esquecimento';
    case FalhaSistema = 'falha_sistema';
    case ServicoExterno = 'servico_externo';
    case DeclaracaoComparecimento = 'declaracao_comparecimento';
    case Atestado = 'atestado';
    case Acordo = 'acordo';
    case HorarioErrado = 'horario_errado';
    case MarcacaoDuplicada = 'marcacao_duplicada';
    case MarcouPorOutro = 'marcou_por_outro';
    case SemAcessoAoApp = 'sem_acesso_ao_app';
    case Outro = 'outro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Esquecimento => 'Esqueceu de marcar',
            self::FalhaSistema => 'Falha do sistema',
            self::ServicoExterno => 'Serviço externo',
            self::DeclaracaoComparecimento => 'Declaração de comparecimento',
            self::Atestado => 'Atestado',
            self::Acordo => 'Acordo',
            self::HorarioErrado => 'Horário errado',
            self::MarcacaoDuplicada => 'Marcação duplicada',
            self::MarcouPorOutro => 'Marcou por outro colaborador',
            self::SemAcessoAoApp => 'Sem acesso ao app',
            self::Outro => 'Outro',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Esquecimento => 'gray',
            self::FalhaSistema => 'danger',
            self::ServicoExterno => 'info',
            self::DeclaracaoComparecimento => 'info',
            self::Atestado => 'info',
            self::Acordo => 'primary',
            self::HorarioErrado => 'warning',
            self::MarcacaoDuplicada => 'warning',
            self::MarcouPorOutro => 'danger',
            self::SemAcessoAoApp => 'gray',
            self::Outro => 'gray',
        };
    }
}
