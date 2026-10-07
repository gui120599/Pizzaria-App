<?php

namespace App\Enums;

/**
 * Por onde um item entra no pedido. Decide quais produtos podem ser lançados
 * (ver LancamentoItemPedidoService): cada canal só vende o que mostra.
 */
enum CanalLancamentoEnum: string
{
    /** Cardápio público (delivery/retirada): produto e categoria marcados para o cardápio. */
    case CARDAPIO = 'cardapio';

    /** Seletor interno (garçom, balcão, telas legadas): cardápio do garçom. */
    case SALAO = 'salao';

    /** Cliente pedindo pelo QR da mesa: vende o mesmo que o cardápio público. */
    case MESA_QR = 'mesa_qr';
}
