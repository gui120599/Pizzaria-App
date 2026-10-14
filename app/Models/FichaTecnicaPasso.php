<?php

namespace App\Models;

use App\Observers\FichaTecnicaVersaoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([FichaTecnicaVersaoObserver::class])]
class FichaTecnicaPasso extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ficha_tecnica_passos';

    protected $fillable = [
        'ficha_tecnica_id',
        'ordem',
        'titulo',
        'descricao',
        'foto',
        'setor_producao_id',
        'tempo_segundos',
        'temperatura_alvo',
        'ponto_critico',
    ];

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
            'tempo_segundos' => 'integer',
            'ponto_critico' => 'boolean',
        ];
    }

    public function fichaTecnica(): BelongsTo
    {
        return $this->belongsTo(FichaTecnica::class, 'ficha_tecnica_id');
    }

    public function setor(): BelongsTo
    {
        return $this->belongsTo(SetorProducao::class, 'setor_producao_id');
    }

    /** Passos do setor do tablet mais os gerais (sem setor). */
    public function scopeDoSetor(Builder $query, int $setorProducaoId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('setor_producao_id')
            ->orWhere('setor_producao_id', $setorProducaoId));
    }
}
