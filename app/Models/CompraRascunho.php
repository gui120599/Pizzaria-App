<?php

namespace App\Models;

use App\Enums\Compras\StatusRascunhoCompra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prévia de compra avulsa (Fase 5): o que o Claude enviou (`entrada`) e o
 * que o sistema calculou (`previa`). Só vira Compra na confirmação pelo
 * mesmo usuário (CompraAvulsaService).
 */
class CompraRascunho extends Model
{
    protected $table = 'compra_rascunhos';

    protected $fillable = [
        'uuid',
        'user_id',
        'prestador_id',
        'fornecedor_novo',
        'entrada',
        'previa',
        'status',
        'expira_em',
        'confirmado_em',
        'compra_id',
    ];

    protected function casts(): array
    {
        return [
            'fornecedor_novo' => 'array',
            'entrada' => 'array',
            'previa' => 'array',
            'status' => StatusRascunhoCompra::class,
            'expira_em' => 'datetime',
            'confirmado_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function expirou(): bool
    {
        return $this->expira_em->isPast();
    }
}
