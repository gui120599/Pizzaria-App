<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
            'par_qtd_maxima_por_pedido' => 'integer',
        ];
    }

    /**
     * Preço do gatilho na campanha. Vazio ou zero = sem override (o produto
     * mantém o preço dele): o campo Money do formulário transforma vazio em
     * 0,00, e um override 0 venderia o gatilho de graça.
     */
    protected function parPrecoGatilhoOverride(): Attribute
    {
        $normalizar = fn ($valor): ?string => $valor !== null && $valor !== '' && (float) $valor > 0
            ? number_format((float) $valor, 2, '.', '')
            : null;

        return Attribute::make(get: $normalizar, set: $normalizar);
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
