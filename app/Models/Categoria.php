<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categoria extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $dates = ['deleted_at'];
    protected $fillable = [
        'categoria_nome',
        'categoria_ordem',
        'categoria_cardapio',
        'categoria_permite_sabores',
        'categoria_max_sabores',
    ];

    protected $casts = [
        'categoria_cardapio'        => 'boolean',
        'categoria_permite_sabores' => 'boolean',
        'categoria_max_sabores'     => 'integer',
        'categoria_ordem'           => 'integer',
    ];

    public function produtos()
    {
        return $this->hasMany(Produto::class, "produto_categoria_id");
    }

    public function historicosPrecos(): HasMany
    {
        return $this->hasMany(ProdutoPrecoHistorico::class, 'categoria_id');
    }

    public function ultimoHistoricoPreco(): HasOne
    {
        return $this->hasOne(ProdutoPrecoHistorico::class, 'categoria_id')->latestOfMany();
    }
}
