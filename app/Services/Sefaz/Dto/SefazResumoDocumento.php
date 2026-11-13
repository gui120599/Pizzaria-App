<?php

namespace App\Services\Sefaz\Dto;

use Carbon\Carbon;

/** Resumo de um documento (resNFe/resEvento) vindo do lote de distribuição por NSU. */
final class SefazResumoDocumento
{
    public function __construct(
        public readonly int $nsu,
        public readonly string $chaveAcesso,
        public readonly bool $isEvento,
        public readonly ?string $cnpjEmitente = null,
        public readonly ?string $nomeEmitente = null,
        public readonly ?float $valor = null,
        public readonly ?Carbon $dataEmissao = null,
        public readonly ?string $tipoEvento = null,
    ) {}

    /** Evento de cancelamento da NF-e pelo emitente. */
    public function ehCancelamento(): bool
    {
        return $this->isEvento && $this->tipoEvento === '110111';
    }
}
