<?php

namespace App\Models\Concerns;

use App\Models\PontoHistorico;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Models do módulo de ponto com trilha de auditoria em ponto_historicos
 * (gravada pelos observers do módulo).
 */
trait TemHistoricoPonto
{
    public function historicos(): MorphMany
    {
        return $this->morphMany(PontoHistorico::class, 'auditavel')->latest('id');
    }
}
