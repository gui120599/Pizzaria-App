<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Forma de pagamento COMBINADA no atendimento do pedido — dado
 * informativo/planejado (ver migration create_pagamentos_pedidos_table),
 * nunca uma cobrança real. Não confundir com PagamentosVenda, que registra
 * pagamento de verdade vinculado a uma Venda/sessão de caixa.
 */
class PagamentosPedido extends Model
{
    use HasFactory;

    protected $table = 'pagamentos_pedidos';

    protected $fillable = [
        'pg_pedido_pedido_id',
        'pg_pedido_opcaopagamento_id',
        'pg_pedido_opcaopagamento_nome',
        'pg_pedido_valor',
        'pg_pedido_valor_troco_para',
        'pg_pedido_ordem',
    ];

    protected $casts = [
        'pg_pedido_valor' => 'decimal:2',
        'pg_pedido_valor_troco_para' => 'decimal:2',
        'pg_pedido_ordem' => 'integer',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pg_pedido_pedido_id');
    }

    public function opcaoPagamento(): BelongsTo
    {
        return $this->belongsTo(OpcoesPagamento::class, 'pg_pedido_opcaopagamento_id');
    }
}
