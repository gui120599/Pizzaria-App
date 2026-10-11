<?php

namespace App\Models;

use App\Enums\TipoCorrecaoPagamentoEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha de uma correção de lançamento (forma do pagamento, divisão, forma do
 * fiado recebido ou do troco) — ver App\Services\CorrecaoPagamentoService.
 */
class CorrecaoPagamento extends Model
{
    protected $table = 'correcoes_pagamento';

    protected $fillable = [
        'tipo',
        'sessao_caixa_id',
        'venda_id',
        'pagamento_venda_id',
        'lancamento_pagamento_id',
        'antes',
        'depois',
        'justificativa',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCorrecaoPagamentoEnum::class,
            'antes' => 'array',
            'depois' => 'array',
        ];
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
