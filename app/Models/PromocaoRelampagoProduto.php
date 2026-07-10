<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromocaoRelampagoProduto extends Model
{
    use HasFactory;

    protected $table = 'promocao_relampago_produtos';

    protected $fillable = [
        'prp_promocao_id',
        'prp_produto_id',
        'prp_preco_promocional',
        'prp_qtd_total',
        'prp_qtd_vendida',
    ];

    protected function casts(): array
    {
        return [
            'prp_preco_promocional' => 'decimal:2',
            'prp_qtd_total' => 'integer',
            'prp_qtd_vendida' => 'decimal:2',
        ];
    }

    public function promocao(): BelongsTo
    {
        return $this->belongsTo(PromocaoRelampago::class, 'prp_promocao_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'prp_produto_id');
    }

    /** Unidades restantes do sublimite deste produto; null quando não há sublimite. */
    public function saldoDisponivel(): ?float
    {
        if ($this->prp_qtd_total === null) {
            return null;
        }

        return max(0.0, (float) $this->prp_qtd_total - (float) $this->prp_qtd_vendida);
    }

    public function esgotado(): bool
    {
        $saldo = $this->saldoDisponivel();

        return $saldo !== null && $saldo <= 0;
    }
}
