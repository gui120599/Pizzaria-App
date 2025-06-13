<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categoria extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $dates = ['deleted_at'];
    protected $fillable = [
        "categoria_nome",
        "categoria_pai_id",
        "categoria_cardapio"
    ];

    public function produtos()
    {
        return $this->hasMany(Produto::class, "produto_categoria_id");
    }

    public function parent()
    {
        return $this->belongsTo(Categoria::class, 'categoria_pai_id');
    }

    public function children()
    {
        return $this->hasMany(Categoria::class, 'categoria_pai_id')->with('children'); // Isso faz o eager load recorrente
    }
}
