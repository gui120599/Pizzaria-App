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
        public readonly ?int $promocaoAdicionalRegraId = null,
    ) {}

    public function precoFinal(): float
    {
        return round($this->valorUnitario - $this->descontoUnitario, 2);
    }

    public function temPromocaoRelampago(): bool
    {
        return $this->promocaoId !== null;
    }

    /** O preço deste item veio de um par_preco_gatilho_override de promoção adicional. */
    public function temPromocaoAdicional(): bool
    {
        return $this->promocaoAdicionalRegraId !== null;
    }
}
