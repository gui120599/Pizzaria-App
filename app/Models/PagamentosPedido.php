<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Forma de pagamento COMBINADA no atendimento do pedido — dado
 * informativo/planejado (ver migration create_pagamentos_pedidos_table),
 * nunca uma cobrança real. Não confundir com PagamentosVenda, que registra
 * pagamento de verdade vinculado a uma Venda/sessão de caixa. O Nova Venda
 * cria um PagamentosVenda para cada forma combinada (pg_venda_pagamento_pedido_id);
 * pg_pedido_pago marca a forma que o atendente já conferiu no pedido (ex.: PIX CNPJ).
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
        'pg_pedido_pago',
        'pg_pedido_pago_em',
        'pg_pedido_pago_usuario_id',
        'pg_pedido_ordem',
    ];

    protected $casts = [
        'pg_pedido_valor' => 'decimal:2',
        'pg_pedido_valor_troco_para' => 'decimal:2',
        'pg_pedido_pago' => 'boolean',
        'pg_pedido_pago_em' => 'datetime',
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

    /** Quem marcou "pagamento já efetuado" no pedido. */
    public function usuarioPagou(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pg_pedido_pago_usuario_id');
    }

    /** Pagamentos de venda criados a partir desta forma combinada. */
    public function pagamentosVenda(): HasMany
    {
        return $this->hasMany(PagamentosVenda::class, 'pg_venda_pagamento_pedido_id');
    }
}
