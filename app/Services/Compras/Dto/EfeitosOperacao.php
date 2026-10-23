<?php

namespace App\Services\Compras\Dto;

use App\Enums\ContaPagarOperacaoEnum;
use App\Enums\EfeitoEstoqueEnum;
use App\Enums\PoliticaCustoEnum;

/**
 * Efeitos de uma operação fiscal de entrada (matriz 4.4 do plano
 * docs/planos/operacoes-fiscais-entrada-nfe.md). Ainda não é consumida
 * pelo CompraService::confirmar() — só descreve o que cada operação deveria fazer.
 */
final readonly class EfeitosOperacao
{
    public function __construct(
        public EfeitoEstoqueEnum $estoque,
        public PoliticaCustoEnum $politicaCusto,
        public ContaPagarOperacaoEnum $contaPagar,
        public bool $exigeVinculo = false,
        public bool $exigeConfirmacao = false,
        public bool $bloqueiaConfirmacao = false,
        public bool $registraSemEfeito = false,
    ) {}

    public function geraEntradaEstoque(): bool
    {
        return $this->estoque === EfeitoEstoqueEnum::ENTRADA;
    }
}
