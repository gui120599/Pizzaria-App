<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Marca extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'marcas';

    protected $fillable = [
        'marca_nome',
        'marca_imagem',
    ];

    public function itensCompra(): HasMany
    {
        return $this->hasMany(CompraItem::class, 'ci_marca_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(EstoqueLote::class, 'lote_marca_id');
    }
}
