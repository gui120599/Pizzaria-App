<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Razão (ledger) de consumo da promoção adicional: uma linha por item de
 * pedido "oferta" aceito. Mesma função do PromocaoConsumo do relâmpago —
 * tornar o estorno idempotente e permitir reconciliar o saldo materializado
 * a partir dos itens.
 */
class PromocaoAdicionalConsumo extends Model
{
    use HasFactory;

    protected $table = 'promocao_adicional_consumos';

    protected $fillable = [
        'pac_regra_id',
        'pac_oferta_id',
        'pac_item_pedido_gatilho_id',
        'pac_item_pedido_oferta_id',
        'pac_pedido_id',
        'pac_valor_adicional_cobrado',
        'pac_quantidade',
        'pac_revertido_em',
    ];

    protected function casts(): array
    {
        return [
            'pac_valor_adicional_cobrado' => 'decimal:2',
            'pac_quantidade' => 'decimal:2',
            'pac_revertido_em' => 'datetime',
        ];
    }

    public function regra(): BelongsTo
    {
        return $this->belongsTo(PromocaoAdicionalRegra::class, 'pac_regra_id');
    }

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(PromocaoAdicionalOferta::class, 'pac_oferta_id');
    }

    public function itemPedidoGatilho(): BelongsTo
    {
        return $this->belongsTo(ItensPedido::class, 'pac_item_pedido_gatilho_id');
    }

    public function itemPedidoOferta(): BelongsTo
    {
        return $this->belongsTo(ItensPedido::class, 'pac_item_pedido_oferta_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pac_pedido_id');
    }

    public function revertido(): bool
    {
        return $this->pac_revertido_em !== null;
    }
}
