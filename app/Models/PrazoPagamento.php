<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrazoPagamento extends Model
{
    use SoftDeletes;

    protected $table = 'prazos_pagamento';

    protected $fillable = [
        'prazo_pagamento_nome',
        'prazo_pagamento_ativo',
        'prazo_pagamento_observacoes',
    ];

    protected function casts(): array
    {
        return [
            'prazo_pagamento_ativo' => 'boolean',
        ];
    }

    /** Parcelas do prazo (dias corridos + percentual do valor), ordenadas. */
    public function parcelas(): HasMany
    {
        return $this->hasMany(PrazoPagamentoParcela::class, 'prazo_pagamento_id')
            ->orderBy('parcela_numero');
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('prazo_pagamento_ativo', true);
    }
}
