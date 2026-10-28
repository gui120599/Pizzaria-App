<?php

namespace App\Models;

use App\Enums\FormaPagamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrazoPagamentoParcela extends Model
{
    protected $table = 'prazo_pagamento_parcelas';

    protected $fillable = [
        'prazo_pagamento_id',
        'parcela_numero',
        'parcela_dias',
        'parcela_percentual',
        'parcela_forma_pagamento',
    ];

    protected function casts(): array
    {
        return [
            'parcela_numero' => 'integer',
            'parcela_dias' => 'integer',
            'parcela_percentual' => 'decimal:4',
            'parcela_forma_pagamento' => FormaPagamento::class,
        ];
    }

    public function prazoPagamento(): BelongsTo
    {
        return $this->belongsTo(PrazoPagamento::class, 'prazo_pagamento_id');
    }
}
