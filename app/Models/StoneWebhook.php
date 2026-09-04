<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoneWebhook extends Model
{
    protected $table = 'stone_webhooks';

    protected $fillable = [
        'stw_evento',
        'stw_hook_id',
        'stw_charge_id',
        'stw_charge_code',
        'stw_order_id',
        'stw_order_code',
        'stw_venda_id',
        'stw_stone_pedido_id',
        'stw_pagamento_venda_id',
        'stw_payload',
        'stw_autenticado',
        'stw_processado_em',
    ];

    protected function casts(): array
    {
        return [
            'stw_payload' => 'array',
            'stw_autenticado' => 'boolean',
            'stw_processado_em' => 'datetime',
        ];
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'stw_venda_id');
    }

    public function stonePedido(): BelongsTo
    {
        return $this->belongsTo(StonePedido::class, 'stw_stone_pedido_id');
    }

    public function pagamentoVenda(): BelongsTo
    {
        return $this->belongsTo(PagamentosVenda::class, 'stw_pagamento_venda_id');
    }
}
