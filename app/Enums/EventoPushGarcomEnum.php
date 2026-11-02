<?php

namespace App\Enums;

/**
 * Eventos que viram push no Painel do Garçom. Para um evento novo basta um
 * caso aqui e o gatilho correspondente.
 */
enum EventoPushGarcomEnum: string
{
    case CHAMOU_GARCOM = 'CHAMOU_GARCOM';
    case PEDIU_CONTA = 'PEDIU_CONTA';
    case APROVAR_PEDIDO = 'APROVAR_PEDIDO';
    case PEDIDO_PRONTO = 'PEDIDO_PRONTO';
    case ABRIR_MESA = 'ABRIR_MESA';
    case TESTE = 'TESTE';

    public function label(): string
    {
        return match ($this) {
            self::CHAMOU_GARCOM => 'Cliente chamou o garçom',
            self::PEDIU_CONTA => 'Cliente pediu a conta',
            self::APROVAR_PEDIDO => 'Pedido do QR para aprovar',
            self::PEDIDO_PRONTO => 'Pedido pronto',
            self::ABRIR_MESA => 'Cliente quer abrir a mesa',
            self::TESTE => 'Teste de notificação',
        };
    }

    /** Validade da mensagem no serviço de push, em segundos. */
    public function ttl(): int
    {
        return (int) config('pizzaria.push.ttl.'.$this->value, 300);
    }

    /** Eventos que o garçom pode ligar/desligar na tela do celular. */
    public function ehConfiguravel(): bool
    {
        return $this !== self::TESTE;
    }

    /**
     * @return list<self>
     */
    public static function configuraveis(): array
    {
        return array_values(array_filter(self::cases(), fn (self $evento): bool => $evento->ehConfiguravel()));
    }
}
