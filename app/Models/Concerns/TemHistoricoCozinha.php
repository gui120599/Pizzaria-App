<?php

namespace App\Models\Concerns;

use App\Models\CozinhaHistorico;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Cadastros do módulo de cozinha com trilha de auditoria em
 * cozinha_historicos (gravada pelos observers do módulo).
 */
trait TemHistoricoCozinha
{
    public function historicos(): MorphMany
    {
        return $this->morphMany(CozinhaHistorico::class, 'auditavel')->latest('id');
    }
}
