<?php

namespace App\Models;

use App\Enums\FormaPagamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um pagamento (parcial ou total) de um lançamento. Um lançamento pode ter N pagamentos
 * (parcelas); status/valor pago do lançamento são derivados da soma destas linhas — ver
 * Lancamento::recalcularStatus(), disparado pelos eventos saved/deleted abaixo.
 */
class LancamentoPagamento extends Model
{
    protected $table = 'lancamento_pagamentos';

    protected $fillable = [
        'lancamento_id',
        'sessao_caixa_id',
        'valor',
        'data_pagamento',
        'forma_pagamento',
        'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'data_pagamento' => 'date',
            'forma_pagamento' => FormaPagamento::class,
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (LancamentoPagamento $pagamento) => $pagamento->lancamento?->recalcularStatus());
        static::deleted(fn (LancamentoPagamento $pagamento) => $pagamento->lancamento?->recalcularStatus());
    }

    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(Lancamento::class, 'lancamento_id');
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }
}
