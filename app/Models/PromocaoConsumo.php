<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Razão (ledger) de consumo da promoção relâmpago: uma linha por item de pedido.
 * Existe para tornar o estorno idempotente — o cancelamento de um pedido cujos
 * itens já foram removidos não pode devolver as unidades duas vezes — e para
 * permitir reconciliar o contador materializado a partir dos itens.
 */
class PromocaoConsumo extends Model
{
    use HasFactory;

    protected $table = 'promocao_consumos';

    protected $fillable = [
        'consumo_promocao_id',
        'consumo_promocao_produto_id',
        'consumo_item_pedido_id',
        'consumo_pedido_id',
        'consumo_quantidade',
        'consumo_revertido_em',
    ];

    protected function casts(): array
    {
        return [
            'consumo_quantidade' => 'decimal:2',
            'consumo_revertido_em' => 'datetime',
        ];
    }

    public function promocao(): BelongsTo
    {
        return $this->belongsTo(PromocaoRelampago::class, 'consumo_promocao_id');
    }

    public function promocaoProduto(): BelongsTo
    {
        return $this->belongsTo(PromocaoRelampagoProduto::class, 'consumo_promocao_produto_id');
    }

    public function itemPedido(): BelongsTo
    {
        return $this->belongsTo(ItensPedido::class, 'consumo_item_pedido_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'consumo_pedido_id');
    }

    public function revertido(): bool
    {
        return $this->consumo_revertido_em !== null;
    }
}
