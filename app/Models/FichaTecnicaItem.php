<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FichaTecnicaItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'ficha_tecnica_itens';

    protected $fillable = [
        'fti_produto_id',
        'fti_insumo_id',
        'fti_quantidade',
        'fti_unidade',
        'fti_percentual_perda',
    ];

    protected function casts(): array
    {
        return [
            'fti_quantidade' => 'decimal:4',
            'fti_percentual_perda' => 'decimal:2',
        ];
    }

    /** Produto produzido (pai) dono desta linha de receita. */
    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'fti_produto_id');
    }

    /** Insumo/componente consumido (pode ser outro produzido). */
    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'fti_insumo_id');
    }

    /** Fator de correção pela perda no preparo (1 + perda%). */
    public function fatorPerda(): float
    {
        return 1 + ((float) $this->fti_percentual_perda / 100);
    }

    /** Custo deste item: custo unitário do insumo × quantidade × fator de perda. */
    public function custo(array $visitados = []): float
    {
        if (! $this->insumo) {
            return 0.0;
        }

        return $this->insumo->custoUnitario($visitados) * (float) $this->fti_quantidade * $this->fatorPerda();
    }
}
