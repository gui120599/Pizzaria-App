<?php

namespace App\Models;

use App\Enums\MovimentacaoTipoEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MovimentacaoBalanco extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'movimentacoes_balanco';

    protected $fillable = [
        'mbal_produto_id',
        'mbal_usuario_id',
        'mbal_quantidade_sistema',
        'mbal_quantidade_balanco',
        'mbal_quantidade_ajuste',
        'mbal_tipo_movimentacao',
        'mbal_observacao',
        'mbal_data_balanco',
    ];

    protected function casts(): array
    {
        return [
            'mbal_quantidade_sistema' => 'decimal:3',
            'mbal_quantidade_balanco' => 'decimal:3',
            'mbal_quantidade_ajuste'  => 'decimal:3',
            'mbal_tipo_movimentacao'  => MovimentacaoTipoEnum::class,
            'mbal_data_balanco'       => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'mbal_produto_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mbal_usuario_id');
    }

    /** Movimentações de estoque geradas por este balanço. */
    public function movimentacoesProduto(): MorphMany
    {
        return $this->morphMany(MovimentacaoProduto::class, 'mov_referencia');
    }
}
