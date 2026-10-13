<?php

namespace App\Exceptions;

/**
 * Finalização bloqueada por pendências nos itens (ver
 * ChecklistExecucaoService::validarFinalizacao()).
 */
class ChecklistFinalizacaoException extends ChecklistException
{
    /** @param  array<int, list<string>>  $erros  mensagens por id do item da execução */
    public function __construct(public readonly array $erros)
    {
        parent::__construct('Há itens pendentes no checklist.');
    }

    /** Id do primeiro item com pendência (na ordem do checklist). */
    public function primeiroItemId(): ?int
    {
        return array_key_first($this->erros);
    }
}
