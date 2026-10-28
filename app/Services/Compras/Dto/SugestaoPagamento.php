<?php

namespace App\Services\Compras\Dto;

use App\Enums\BaseVencimentoEnum;
use App\Enums\VencimentoOrigemEnum;
use Illuminate\Support\Carbon;

/**
 * Pré-preenchimento do modal "Confirmar compra". Só sugere: nenhuma linha
 * vem marcada como paga.
 */
final class SugestaoPagamento
{
    /**
     * @param  array<string, array{vencimento: string, valor: string, forma_pagamento: ?string, ja_pago: false, conta_financeira_id: ?int, sessao_caixa_id: null}>  $parcelas  chaves UUID
     * @param  list<string>  $avisos
     * @param  list<string>  $fontes
     */
    public function __construct(
        public readonly Carbon $dataEntrada,
        public readonly Carbon $dataBase,
        public readonly BaseVencimentoEnum $baseVencimento,
        public readonly ?int $prazoPagamentoId,
        public readonly VencimentoOrigemEnum $origem,
        public readonly array $parcelas,
        public readonly array $avisos = [],
        public readonly array $fontes = [],
    ) {}
}
