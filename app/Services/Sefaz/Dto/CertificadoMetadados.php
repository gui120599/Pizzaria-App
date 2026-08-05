<?php

namespace App\Services\Sefaz\Dto;

use DateTimeInterface;

/** Dados extraídos de um certificado A1 (.pfx) já aberto com sucesso. */
final class CertificadoMetadados
{
    public function __construct(
        public readonly ?string $cnpj,
        public readonly ?string $nomeTitular,
        public readonly DateTimeInterface $validade,
    ) {}
}
