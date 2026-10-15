<?php

namespace App\Models;

use App\Enums\Ponto\TipoEscala;
use App\Models\Concerns\TemHistoricoPonto;
use App\Observers\PontoAuditoriaObserver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([PontoAuditoriaObserver::class])]
class Escala extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoPonto;

    protected $table = 'escalas';

    protected $fillable = [
        'nome',
        'tipo',
        'tolerancia_marcacao_min',
        'tolerancia_diaria_min',
        'intervalo_minimo_min',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoEscala::class,
            'tolerancia_marcacao_min' => 'integer',
            'tolerancia_diaria_min' => 'integer',
            'intervalo_minimo_min' => 'integer',
        ];
    }

    public function dias(): HasMany
    {
        return $this->hasMany(EscalaDia::class, 'escala_id')->orderBy('dia_semana');
    }

    public function colaboradores(): HasMany
    {
        return $this->hasMany(ColaboradorEscala::class, 'escala_id');
    }

    /** Configuração do dia da semana do dia operacional informado (null = dia não cadastrado). */
    public function diaDe(CarbonInterface $diaOperacional): ?EscalaDia
    {
        return $this->dias->firstWhere('dia_semana', $diaOperacional->dayOfWeekIso);
    }
}
