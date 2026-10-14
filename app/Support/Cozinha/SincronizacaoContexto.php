<?php

namespace App\Support\Cozinha;

use Closure;

/**
 * Marca que a mudança no pedido está sendo feita PELO módulo de cozinha
 * (PedidoStatusGateway). O observer de produção do pedido ignora os eventos
 * enquanto o contexto está ativo — sem isso, a cozinha mudar o pedido faria o
 * observer tentar regerar ou forçar os itens de produção (laço).
 */
final class SincronizacaoContexto
{
    private static int $profundidade = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function executando(Closure $callback): mixed
    {
        self::$profundidade++;

        try {
            return $callback();
        } finally {
            self::$profundidade--;
        }
    }

    public static function ativo(): bool
    {
        return self::$profundidade > 0;
    }
}
