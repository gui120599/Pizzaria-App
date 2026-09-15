<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Config de um produto-gatilho dentro de uma campanha PromocaoAdicional: o
 * preço de override (se houver) e o limite de aceites por pedido. As N opções
 * de produto que podem ser oferecidas para este gatilho vivem em
 * PromocaoAdicionalOferta (ver ofertas()) — o cliente escolhe 1 dentre elas.
 */
class PromocaoAdicionalRegra extends Model
{
    use HasFactory;

    protected $table = 'promocao_adicional_regras';

    protected $fillable = [
        'par_promocao_id',
        'par_produto_gatilho_id',
        'par_preco_gatilho_override',
        'par_qtd_maxima_por_pedido',
    ];

    protected function casts(): array
    {
        return [
            'par_preco_gatilho_override' => 'decimal:2',
            'par_qtd_maxima_por_pedido' => 'integer',
        ];
    }

    public function promocao(): BelongsTo
    {
        return $this->belongsTo(PromocaoAdicional::class, 'par_promocao_id');
    }

    public function produtoGatilho(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'par_produto_gatilho_id');
    }

    public function ofertas(): HasMany
    {
        return $this->hasMany(PromocaoAdicionalOferta::class, 'pao_regra_id');
    }

    /** Ofertas desta regra que ainda têm saldo — as opções que o cliente pode escolher agora. */
    public function ofertasDisponiveis()
    {
        return $this->ofertas->reject(fn (PromocaoAdicionalOferta $oferta) => $oferta->esgotada());
    }
}
