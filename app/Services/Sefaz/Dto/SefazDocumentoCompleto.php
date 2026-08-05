<?php

namespace App\Services\Sefaz\Dto;

/** XML completo (procNFe) de uma nota, já liberado pela SEFAZ (pós-manifestação). */
final class SefazDocumentoCompleto
{
    public function __construct(
        public readonly string $chaveAcesso,
        public readonly string $xmlCompleto,
    ) {}
}
