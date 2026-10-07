<?php

namespace App\Enums;

/** O que o cliente pediu ao garçom pelo QR da mesa. */
enum TipoChamadoMesaEnum: string
{
    case CHAMAR_GARCOM = 'CHAMAR_GARCOM';
    case PEDIR_CONTA = 'PEDIR_CONTA';
    /** Mesa sem conta aberta: o cliente pede para o garçom abrir. */
    case ABRIR_MESA = 'ABRIR_MESA';

    public function label(): string
    {
        return match ($this) {
            self::CHAMAR_GARCOM => 'Chamou o garçom',
            self::PEDIR_CONTA => 'Pediu a conta',
            self::ABRIR_MESA => 'Quer abrir a mesa',
        };
    }
}
