<?php

namespace App\Services\Nfe\Dto;

use Carbon\CarbonImmutable;

/** Duplicata (<cobr>/<dup>) da cobrança informada na NF-e. */
final class NfeDuplicata
{
    public function __construct(
        public readonly ?string $numero,
        public readonly ?CarbonImmutable $vencimento,
        public readonly float $valor,
    ) {}

    /** @return array{nDup: ?string, dVenc: ?string, vDup: float} */
    public function toArray(): array
    {
        return ['nDup' => $this->numero, 'dVenc' => $this->vencimento?->toDateString(), 'vDup' => $this->valor];
    }
}
