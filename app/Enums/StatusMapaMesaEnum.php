<?php

namespace App\Enums;

/**
 * Status derivado (não persistido) de uma mesa no mapa do Painel do Garçom.
 * A ordem dos cases é a prioridade: o primeiro que se aplica vence.
 */
enum StatusMapaMesaEnum: string
{
    /** Pedido do cliente pelo QR esperando o garçom aprovar. */
    case APROVAR_PEDIDO = 'APROVAR_PEDIDO';
    /** Cliente tocou em "Chamar garçom" no QR. */
    case CHAMOU_GARCOM = 'CHAMOU_GARCOM';
    case CONTA_SOLICITADA = 'CONTA_SOLICITADA';
    case PRONTO = 'PRONTO';
    case EM_PREPARO = 'EM_PREPARO';
    case AGUARDANDO_PEDIDO = 'AGUARDANDO_PEDIDO';
    case ATENDIDA = 'ATENDIDA';
    case LIVRE = 'LIVRE';
    case INATIVA = 'INATIVA';

    public function label(): string
    {
        return match ($this) {
            self::APROVAR_PEDIDO => 'Aprovar pedido',
            self::CHAMOU_GARCOM => 'Chamou o garçom',
            self::CONTA_SOLICITADA => 'Pediu a conta',
            self::PRONTO => 'Pedido pronto',
            self::EM_PREPARO => 'Em preparo',
            self::AGUARDANDO_PEDIDO => 'Aguardando pedido',
            self::ATENDIDA => 'Ocupada',
            self::LIVRE => 'Livre',
            self::INATIVA => 'Inativa',
        };
    }

    /** Classes Tailwind do card no mapa. */
    public function classes(): string
    {
        return match ($this) {
            self::APROVAR_PEDIDO => 'bg-orange-500 text-white ring-orange-600 animate-pulse',
            self::CHAMOU_GARCOM => 'bg-yellow-300 text-yellow-950 ring-yellow-500 animate-pulse',
            self::CONTA_SOLICITADA => 'bg-violet-600 text-white ring-violet-700',
            self::PRONTO => 'bg-emerald-500 text-white ring-emerald-600 animate-pulse',
            self::EM_PREPARO => 'bg-amber-400 text-amber-950 ring-amber-500',
            self::AGUARDANDO_PEDIDO => 'bg-sky-500 text-white ring-sky-600',
            self::ATENDIDA => 'bg-rose-500 text-white ring-rose-600',
            self::LIVRE => 'bg-white text-gray-800 ring-gray-300 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-600',
            self::INATIVA => 'bg-gray-200 text-gray-500 ring-gray-300 dark:bg-gray-900 dark:text-gray-500 dark:ring-gray-700',
        };
    }
}
