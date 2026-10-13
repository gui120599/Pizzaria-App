<?php

namespace App\Models\Concerns;

use App\Models\ChecklistHistorico;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Models do módulo de checklists com trilha de auditoria em
 * checklist_historicos (gravada pelos observers do módulo).
 */
trait TemHistoricoChecklist
{
    public function historicos(): MorphMany
    {
        return $this->morphMany(ChecklistHistorico::class, 'auditavel')->latest('id');
    }
}
