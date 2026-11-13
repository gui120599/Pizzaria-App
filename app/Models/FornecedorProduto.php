<?php

namespace App\Models;

use App\Enums\Compras\OrigemVinculo;
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
        'fp_ean',
        'fp_ultimo_custo_unitario',
        'fp_origem',
        'fp_confirmado_por',
    ];

    protected function casts(): array
    {
        return [
            'fp_fator_conversao' => 'decimal:4',
            'fp_ultimo_custo_unitario' => 'decimal:4',
            'fp_origem' => OrigemVinculo::class,
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

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fp_confirmado_por');
    }
}
