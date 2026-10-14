<?php

namespace App\Exceptions;

use App\Enums\Cozinha\StatusItemProducao;
use RuntimeException;

/** Operação de produção inválida para o estado atual (mensagem pronta para o tablet). */
class ProducaoException extends RuntimeException
{
    public static function transicaoInvalida(StatusItemProducao $de, StatusItemProducao $para): self
    {
        return new self("Não é possível passar de \"{$de->getLabel()}\" para \"{$para->getLabel()}\".");
    }

    public static function versaoDesatualizada(): self
    {
        return new self('Este item foi atualizado em outro aparelho. Toque de novo.');
    }

    public static function operadorObrigatorio(): self
    {
        return new self('Escolha o operador antes de produzir.');
    }

    public static function semAberturaDoSetor(string $setor): self
    {
        return new self("Conclua o checklist de abertura de {$setor} antes de iniciar a produção.");
    }

    public static function pedidoComItensEmProducao(int $pendentes): self
    {
        return new self("Ainda há {$pendentes} item(ns) em produção neste pedido.");
    }
}
