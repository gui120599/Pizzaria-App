<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * O que está sendo cobrado quando o StonePedido é criado.
 *
 * - Venda: fluxo original do PDV (OperarVenda) — a Venda já existe antes do
 *   envio à maquininha.
 * - Pedido: um Pedido avulso (balcão/retirada/delivery) é enviado sem Venda;
 *   o webhook charge.paid cria a Venda (StoneVendaAutomaticaService).
 * - SessaoMesa: a conta de uma sessão de mesa é enviada sem Venda; o webhook
 *   cria a Venda com os itens de todos os pedidos ativos da sessão.
 */
enum StonePedidoOrigem: string implements HasColor, HasLabel
{
    case Venda = 'venda';
    case Pedido = 'pedido';
    case SessaoMesa = 'sessao_mesa';

    public function getLabel(): string
    {
        return match ($this) {
            self::Venda => 'Venda (PDV)',
            self::Pedido => 'Pedido',
            self::SessaoMesa => 'Sessão de mesa',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Venda => 'primary',
            self::Pedido => 'info',
            self::SessaoMesa => 'warning',
        };
    }
}
