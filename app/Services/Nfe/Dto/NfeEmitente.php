<?php

namespace App\Services\Nfe\Dto;

/** Emitente (fornecedor) extraído do bloco <emit> da NF-e. */
final class NfeEmitente
{
    public function __construct(
        public readonly string $documento,
        public readonly ?string $razaoSocial,
        public readonly ?string $nomeFantasia,
        public readonly ?string $inscricaoEstadual,
        public readonly ?string $email,
        public readonly ?string $telefone,
        public readonly ?string $cep,
        public readonly ?string $endereco,
        public readonly ?string $numero,
        public readonly ?string $complemento,
        public readonly ?string $bairro,
        public readonly ?string $cidade,
        public readonly ?string $uf,
    ) {}

    public function isPessoaFisica(): bool
    {
        return strlen($this->documento) === 11;
    }
}
