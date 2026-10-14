<?php

namespace App\Exceptions;

use LogicException;

/**
 * Tentativa de alterar ou apagar um registro append-only (marcação de ponto,
 * movimentação financeira). A correção é sempre por um novo registro: ajuste
 * de ponto ou estorno.
 */
class RegistroImutavelException extends LogicException
{
    public static function alteracao(string $registro, string $comoCorrigir): self
    {
        return new self("{$registro} não pode ser alterado(a) nem apagado(a). {$comoCorrigir}");
    }
}
