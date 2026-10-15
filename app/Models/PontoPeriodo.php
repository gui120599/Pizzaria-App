<?php

namespace App\Models;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\StatusPeriodo;
use App\Models\Concerns\TemHistoricoPonto;
use App\Observers\PontoAuditoriaObserver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Período de apuração (semanal, quinzenal ou mensal). Fora de "aberto",
 * marcações e ajustes do período não mudam mais a apuração.
 */
#[ObservedBy([PontoAuditoriaObserver::class])]
class PontoPeriodo extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoPonto;

    protected $table = 'ponto_periodos';

    protected $fillable = [
        'periodicidade',
        'data_inicio',
        'data_fim',
        'data_pagamento',
        'status',
        'fechado_por',
        'fechado_em',
    ];

    protected function casts(): array
    {
        return [
            'periodicidade' => PeriodicidadeRepasse::class,
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'data_pagamento' => 'date',
            'status' => StatusPeriodo::class,
            'fechado_em' => 'datetime',
        ];
    }

    public function dias(): HasMany
    {
        return $this->hasMany(PontoDia::class, 'ponto_periodo_id');
    }

    public function repasses(): HasMany
    {
        return $this->hasMany(Repasse::class, 'ponto_periodo_id');
    }

    public function fechadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fechado_por');
    }

    public function scopeContendo(Builder $query, CarbonInterface $dia): Builder
    {
        return $query
            ->whereDate('data_inicio', '<=', $dia->toDateString())
            ->whereDate('data_fim', '>=', $dia->toDateString());
    }

    public function scopeDaPeriodicidade(Builder $query, PeriodicidadeRepasse $periodicidade): Builder
    {
        return $query->where('periodicidade', $periodicidade);
    }

    public function estaTravado(): bool
    {
        return $this->status->travado();
    }

    public function contem(CarbonInterface $dia): bool
    {
        return $dia->copy()->startOfDay()->betweenIncluded($this->data_inicio, $this->data_fim);
    }

    /** "01/10–15/10/2026" */
    public function rotulo(): string
    {
        return $this->data_inicio->format('d/m').'–'.$this->data_fim->format('d/m/Y');
    }
}
