<?php

namespace App\Support\Cozinha;

/**
 * Chave geral do módulo de cozinha (config cozinha.ativo). Desligado, o
 * sistema se comporta como antes dos tablets.
 */
class ModuloCozinha
{
    public static function ativo(): bool
    {
        return (bool) config('cozinha.ativo', false);
    }
}
