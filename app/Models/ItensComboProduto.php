<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItensComboProduto extends Model
{
    /** @use HasFactory<\Database\Factories\ItensComboProdutoFactory> */
    use HasFactory,SoftDeletes;

    protected $table = 'itens_combo_produtos';

    protected $fillable = [
        'item_combo_produto_combo_id',
        'item_combo_produto_produto_id',
        'item_combo_produto_valor_produto',
        'item_combo_produto_valor_desconto',
        'item_combo_produto_valor_total',
    ];

    public function combo()
    {
        return $this->belongsTo(ComboProduto::class, 'item_combo_produto_combo_id');
    }

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'item_combo_produto_produto_id');
    }
}
