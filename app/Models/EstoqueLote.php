<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EstoqueLote extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'estoque_lotes';

    protected $fillable = [
        'lote_produto_id',
        'lote_codigo',
        'lote_validade',
        'lote_qtd_inicial',
        'lote_qtd_atual',
        'lote_custo_unitario',
        'lote_data_entrada',
        'lote_status',
    ];

    protected function casts(): array
    {
        return [
            'lote_validade' => 'date',
            'lote_data_entrada' => 'datetime',
            'lote_qtd_inicial' => 'decimal:3',
            'lote_qtd_atual' => 'decimal:3',
            'lote_custo_unitario' => 'decimal:4',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'lote_produto_id');
    }

    /** Lotes com saldo disponível para baixa. */
    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('lote_status', 'ativo')->where('lote_qtd_atual', '>', 0);
    }

    /** Ordenação FEFO: vence primeiro, sai primeiro (nulos por último). */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderByRaw('lote_validade IS NULL, lote_validade ASC')->orderBy('id');
    }
}
