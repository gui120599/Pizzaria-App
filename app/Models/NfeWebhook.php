<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfeWebhook extends Model
{
    protected $table = 'nfe_webhooks';

    protected $fillable = [
        'nfw_evento',
        'nfw_invoice_id',
        'nfw_venda_id',
        'nfw_payload',
        'nfw_assinatura_valida',
        'nfw_processado_em',
    ];

    protected function casts(): array
    {
        return [
            'nfw_payload' => 'array',
            'nfw_assinatura_valida' => 'boolean',
            'nfw_processado_em' => 'datetime',
        ];
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'nfw_venda_id');
    }
}
