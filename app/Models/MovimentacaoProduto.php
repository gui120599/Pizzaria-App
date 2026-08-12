<?php

namespace App\Models;

use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\MovimentacaoTipoEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class MovimentacaoProduto extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'mov_produto_id',
        'mov_quantidade',
        'mov_custo_unitario',
        'mov_custo_total',
        'mov_tipo',
        'mov_origem',
        'mov_saldo_apos',
        'mov_data',
        'mov_motivo',
        'mov_venda_id',
        'mov_user_id',
        'mov_centro_custo_id',
        'mov_lote_id',
        'mov_referencia_type',
        'mov_referencia_id',
    ];

    protected function casts(): array
    {
        return [
            'mov_tipo' => MovimentacaoTipoEnum::class,
            'mov_origem' => MovimentacaoOrigemEnum::class,
            'mov_quantidade' => 'decimal:3',
            'mov_custo_unitario' => 'decimal:8',
            'mov_custo_total' => 'decimal:2',
            'mov_saldo_apos' => 'decimal:3',
            'mov_data' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mov_user_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'mov_produto_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'mov_centro_custo_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(EstoqueLote::class, 'mov_lote_id');
    }

    /** Documento de origem (Compra, Venda, Inventário...). */
    public function referencia(): MorphTo
    {
        return $this->morphTo('mov_referencia');
    }

    protected static function booted(): void
    {
        static::creating(function (MovimentacaoProduto $movimentacaoProduto) {
            if (empty($movimentacaoProduto->mov_user_id) && Auth::check()) {
                $movimentacaoProduto->mov_user_id = Auth::id();
            }
            if (empty($movimentacaoProduto->mov_data)) {
                $movimentacaoProduto->mov_data = now();
            }
        });
    }
}
