<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlanoReceita extends Model
{
    use SoftDeletes;

    protected $table = 'planos_receitas';

    protected $fillable = [
        'pai_id',
        'codigo',
        'nome',
    ];

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
        return $this->hasMany(Lancamento::class, 'plano_receita_id');
    }
}
