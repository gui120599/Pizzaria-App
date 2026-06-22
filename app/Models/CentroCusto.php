<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CentroCusto extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'centros_custo';

    protected $fillable = [
        'centro_custo_nome',
        'centro_custo_tipo',
    ];

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoProduto::class, 'mov_centro_custo_id');
    }
}
