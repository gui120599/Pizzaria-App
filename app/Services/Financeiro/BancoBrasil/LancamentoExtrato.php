<?php

namespace App\Services\Financeiro\BancoBrasil;

use Illuminate\Support\Carbon;

/** Uma linha do extrato devolvida pela API, já normalizada. */
final readonly class LancamentoExtrato
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public Carbon $dataLancamento,
        public ?Carbon $dataMovimento,
        public float $valor,
        public string $natureza,
        public string $historico,
        public ?string $documento,
        public ?string $infoComplementar,
        public array $payload,
    ) {}
}
