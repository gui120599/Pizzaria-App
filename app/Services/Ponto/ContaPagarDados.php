<?php

namespace App\Services\Ponto;

use App\Enums\FormaPagamento;
use Carbon\CarbonInterface;

/** O que o módulo de ponto manda para o contas a pagar (ver LancamentoGateway). */
final readonly class ContaPagarDados
{
    public function __construct(
        public int $favorecidoId,
        public int $planoDespesaId,
        public float $valor,
        public CarbonInterface $vencimento,
        public CarbonInterface $competencia,
        public string $descricao,
        public ?string $observacoes = null,
        public ?FormaPagamento $formaPagamento = null,
        public ?int $repasseId = null,
        public ?int $adiantamentoId = null,
    ) {}
}
