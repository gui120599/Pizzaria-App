<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Ação registrada em `producao_eventos` (linha do tempo do item). */
enum AcaoProducao: string implements HasColor, HasLabel
{
    case Gerado = 'gerado';
    case Liberado = 'liberado';
    case Iniciado = 'iniciado';
    case Devolvido = 'devolvido';
    case Concluido = 'concluido';
    case Desfeito = 'desfeito';
    case Expedido = 'expedido';
    case Cancelado = 'cancelado';
    case CienciaCancelamento = 'ciencia_cancelamento';
    case Alterado = 'alterado';
    case CienciaAlteracao = 'ciencia_alteracao';
    case Priorizado = 'priorizado';
    case Refeito = 'refeito';
    case ForcadoPeloPainel = 'forcado_pelo_painel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gerado => 'Gerado',
            self::Liberado => 'Liberado',
            self::Iniciado => 'Iniciado',
            self::Devolvido => 'Devolvido à fila',
            self::Concluido => 'Concluído',
            self::Desfeito => 'Desfeito',
            self::Expedido => 'Expedido',
            self::Cancelado => 'Cancelado',
            self::CienciaCancelamento => 'Ciência do cancelamento',
            self::Alterado => 'Alterado',
            self::CienciaAlteracao => 'Ciência da alteração',
            self::Priorizado => 'Priorizado',
            self::Refeito => 'Refeito',
            self::ForcadoPeloPainel => 'Forçado pelo painel',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Gerado, self::Liberado => 'gray',
            self::Iniciado, self::Desfeito, self::Devolvido => 'warning',
            self::Concluido, self::Expedido => 'success',
            self::Cancelado, self::Refeito => 'danger',
            self::Alterado, self::Priorizado, self::ForcadoPeloPainel => 'info',
            self::CienciaCancelamento, self::CienciaAlteracao => 'gray',
        };
    }
}
