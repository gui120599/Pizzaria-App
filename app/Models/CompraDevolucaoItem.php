<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraDevolucaoItem extends Model
{
    protected $table = 'compra_devolucao_itens';

    protected $fillable = [
        'compra_devolucao_id',
        'compra_item_id',
        'quantidade',
        'valor_unitario',
        'valor_total',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:4',
            'valor_unitario' => 'decimal:8',
            'valor_total' => 'decimal:2',
        ];
    }

    public function devolucao(): BelongsTo
    {
        return $this->belongsTo(CompraDevolucao::class, 'compra_devolucao_id');
    }

    public function compraItem(): BelongsTo
    {
        return $this->belongsTo(CompraItem::class, 'compra_item_id');
    }
}
