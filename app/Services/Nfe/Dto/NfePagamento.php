<?php

namespace App\Services\Nfe\Dto;

/** Forma de pagamento (<detPag>) informada na NF-e. tPag 90 = sem pagamento. */
final class NfePagamento
{
    public function __construct(
        public readonly ?string $tipo,
        public readonly ?string $indicador,
        public readonly float $valor,
    ) {}

    public function semPagamento(): bool
    {
        return $this->tipo === '90';
    }

    /** @return array{tPag: ?string, indPag: ?string, vPag: float} */
    public function toArray(): array
    {
        return ['tPag' => $this->tipo, 'indPag' => $this->indicador, 'vPag' => $this->valor];
    }
}
