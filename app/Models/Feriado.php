<?php

namespace App\Models;

use App\Enums\Ponto\AbrangenciaFeriado;
use App\Support\FeriadosNacionais;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Feriado. Recorrente vale todo ano pelo dia/mês de `data`.
 * Ponto facultativo não conta como feriado na apuração.
 */
class Feriado extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'feriados';

    protected $fillable = [
        'data',
        'nome',
        'abrangencia',
        'recorrente_anual',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'abrangencia' => AbrangenciaFeriado::class,
            'recorrente_anual' => 'boolean',
        ];
    }

    public function scopeNaData(Builder $query, CarbonInterface $dia): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereDate('data', $dia->toDateString())
            ->orWhere(fn (Builder $r) => $r
                ->where('recorrente_anual', true)
                ->whereMonth('data', $dia->month)
                ->whereDay('data', $dia->day)));
    }

    /** Feriado que conta na apuração (ignora ponto facultativo). */
    public static function ehFeriado(CarbonInterface $dia): bool
    {
        return static::query()
            ->naData($dia)
            ->where('abrangencia', '!=', AbrangenciaFeriado::Facultativo)
            ->exists();
    }

    /**
     * Grava os feriados nacionais do ano (idempotente). Os de data fixa
     * entram uma vez só, como recorrentes; os móveis, com a data do ano.
     *
     * @return int quantos foram criados
     */
    public static function importarNacionais(int $ano): int
    {
        $criados = 0;

        foreach (FeriadosNacionais::doAno($ano) as $feriado) {
            if ($feriado['recorrente_anual'] && static::withTrashed()
                ->where('recorrente_anual', true)
                ->where('nome', $feriado['nome'])
                ->exists()) {
                continue;
            }

            $criados += static::withTrashed()->firstOrCreate(
                ['data' => $feriado['data']->toDateString(), 'nome' => $feriado['nome']],
                ['abrangencia' => $feriado['abrangencia'], 'recorrente_anual' => $feriado['recorrente_anual']],
            )->wasRecentlyCreated ? 1 : 0;
        }

        return $criados;
    }
}
