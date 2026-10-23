<?php

namespace App\Services\Compras\Dto;

use App\Enums\ConfiancaOperacaoEnum;
use App\Enums\OperacaoEntradaEnum;

/** Operação sugerida para um item (<det>) da NF-e. */
final readonly class ClassificacaoItem
{
    public function __construct(
        public int $indice,
        public ?string $cfop,
        public OperacaoEntradaEnum $operacao,
        public ConfiancaOperacaoEnum $confianca,
    ) {}
}
