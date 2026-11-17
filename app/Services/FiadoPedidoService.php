<?php

namespace App\Services;

use App\Exceptions\FiadoNaoPermitidoException;
use App\Models\Cliente;

/**
 * Pedido combinado como fiado / a prazo no atendimento: dispensa a forma de
 * pagamento, mas só para cliente com limite de crédito que cubra o pedido.
 * A cobrança de verdade (título a receber) nasce no Nova Venda, que já abre a
 * venda desse pedido como fiado (FinalizacaoVendaService revalida o limite).
 */
class FiadoPedidoService
{
    /** Texto gravado em pedido_descricao_pagamento (lido pelo PDV e pelas telas legadas). */
    public const DESCRICAO = 'Fiado / a prazo';

    /**
     * @throws FiadoNaoPermitidoException cliente ausente, sem limite configurado ou com
     *                                    crédito disponível menor que o total
     */
    public function exigirCredito(?Cliente $cliente, float $total): void
    {
        if ($cliente === null) {
            throw new FiadoNaoPermitidoException('Pedido fiado / a prazo exige um cliente cadastrado.');
        }

        $disponivel = $cliente->limiteCreditoDisponivel();

        if ($disponivel === null) {
            throw new FiadoNaoPermitidoException("{$cliente->cliente_nome} não tem limite de crédito configurado — cadastre um limite no cliente antes de vender fiado.");
        }

        if ($total - $disponivel > 0.01) {
            throw new FiadoNaoPermitidoException(sprintf(
                'O pedido (R$ %s) excede o crédito disponível de %s (R$ %s).',
                number_format($total, 2, ',', '.'),
                $cliente->cliente_nome,
                number_format($disponivel, 2, ',', '.'),
            ));
        }
    }
}
