<?php

namespace App\Enums\Compras;

use Filament\Support\Contracts\HasLabel;

/** O quanto o agente pode fazer sozinho nas notas de um fornecedor. */
enum AutonomiaAgente: string implements HasLabel
{
    case Sugerir = 'sugerir';
    case Vincular = 'vincular';
    case Completa = 'completa';

    public const PADRAO = self::Vincular;

    public function getLabel(): string
    {
        return match ($this) {
            self::Sugerir => 'Só sugerir (tudo aguarda aprovação)',
            self::Vincular => 'Vincular a produtos existentes',
            self::Completa => 'Vincular e cadastrar produtos novos',
        };
    }
}
