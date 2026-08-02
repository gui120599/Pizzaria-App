<?php

namespace App\Services\Nfe\Dto;

use Carbon\CarbonImmutable;

/** Item (<det>) da NF-e, ainda não casado com nenhum produto do estoque. */
final class NfeItem
{
    public function __construct(
        public readonly string $codigoFornecedor,
        public readonly string $descricao,
        public readonly ?string $ean,
        public readonly string $unidadeComercial,
        public readonly float $quantidadeComercial,
        public readonly float $valorUnitarioComercial,
        public readonly float $valorTotalBruto,
        public readonly float $valorDesconto,
        public readonly ?string $loteCodigo,
        public readonly ?CarbonImmutable $validade,
    ) {}

    /** Custo unitário já líquido do desconto informado no próprio item. */
    public function custoUnitarioLiquido(): float
    {
        if ($this->quantidadeComercial <= 0) {
            return $this->valorUnitarioComercial;
        }

        return round(($this->valorTotalBruto - $this->valorDesconto) / $this->quantidadeComercial, 4);
    }
}
