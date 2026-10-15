<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Registro com vigência vigente_desde..vigente_ate (fim nulo = em aberto,
 * ambos inclusivos): remuneração e escala do colaborador.
 */
trait TemVigencia
{
    public function scopeVigenteEm(Builder $query, CarbonInterface $data): Builder
    {
        $dia = $data->toDateString();

        return $query
            ->whereDate($this->qualifyColumn('vigente_desde'), '<=', $dia)
            ->where(fn (Builder $q) => $q
                ->whereNull($this->qualifyColumn('vigente_ate'))
                ->orWhereDate($this->qualifyColumn('vigente_ate'), '>=', $dia));
    }

    public function scopeEmAberto(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('vigente_ate'));
    }

    public function vigenteEm(CarbonInterface $data): bool
    {
        return $this->vigente_desde->lte($data->copy()->startOfDay())
            && ($this->vigente_ate === null || $this->vigente_ate->gte($data->copy()->startOfDay()));
    }
}
