<?php

namespace App\Services\Sefaz\Dto;

/** Um lote de resumos retornado pela consulta por NSU (distNSU), com o cursor de continuação. */
final class SefazLoteDistribuicao
{
    /** @param  SefazResumoDocumento[]  $itens */
    public function __construct(
        public readonly array $itens,
        public readonly int $ultNsuRetornado,
        public readonly int $maxNsu,
        public readonly string $cStat,
    ) {}

    /** Ainda há NSU mais recente que o retornado — vale continuar consultando. */
    public function temMais(): bool
    {
        return $this->ultNsuRetornado < $this->maxNsu;
    }
}
