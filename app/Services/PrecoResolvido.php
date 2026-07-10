<?php

namespace App\Services;

/**
 * Preço de uma unidade, resolvido pelo servidor. Segue a convenção de
 * ItensPedido: valor_unitario é o preço cheio e o abatimento vai em desconto.
 */
final class PrecoResolvido
{
    public function __construct(
        public readonly float $valorUnitario,
        public readonly float $descontoUnitario,
        public readonly ?int $promocaoId = null,
        public readonly ?int $promocaoProdutoId = null,
    ) {}

    public function precoFinal(): float
    {
        return round($this->valorUnitario - $this->descontoUnitario, 2);
    }

    public function temPromocaoRelampago(): bool
    {
        return $this->promocaoId !== null;
    }
}
