<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComboProduto extends Model
{
    /** @use HasFactory<\Database\Factories\ComboProdutoFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'combo_produtos';

    protected $fillable = [
        'combo_produto_nome',
        'combo_produto_cardapio',
        'combo_produto_promocional',
        'combo_produto_foto',
        'combo_produto_valor',
    ];

    protected $casts = [
        'combo_produto_cardapio' => 'boolean',
        'combo_produto_promocional' => 'boolean',
        'combo_produto_valor' => 'decimal:2',
    ];
}
