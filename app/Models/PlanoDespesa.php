<?php

namespace App\Models;

use App\Enums\Comportamento;
use App\Enums\Periodicidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlanoDespesa extends Model
{
    use SoftDeletes;

    protected $table = 'planos_despesas';

    protected $fillable = [
        'pai_id',
        'codigo',
        'nome',
        'comportamento',
        'periodicidade',
    ];

    protected function casts(): array
    {
        return [
            'comportamento' => Comportamento::class,
            'periodicidade' => Periodicidade::class,
        ];
    }

    /** Grupo / conta pai (auto-referência). */
    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pai_id');
    }

    /** Contas filhas do grupo. */
    public function filhos(): HasMany
    {
        return $this->hasMany(self::class, 'pai_id');
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class, 'plano_despesa_id');
    }
}
