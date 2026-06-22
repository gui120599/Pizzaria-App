<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FornecedorProduto extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'fornecedor_produtos';

    protected $fillable = [
        'fp_prestador_id',
        'fp_produto_id',
        'fp_codigo_fornecedor',
        'fp_descricao_fornecedor',
        'fp_unidade_compra',
        'fp_fator_conversao',
    ];

    protected function casts(): array
    {
        return [
            'fp_fator_conversao' => 'decimal:4',
        ];
    }

    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class, 'fp_prestador_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'fp_produto_id');
    }
}
