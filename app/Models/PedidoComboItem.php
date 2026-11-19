<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Unidades de uma linha do pedido que entraram numa aplicação de promoção combo. */
class PedidoComboItem extends Model
{
    protected $table = 'pedido_combo_itens';

    protected $fillable = [
        'pci_aplicacao_id',
        'pci_item_pedido_id',
        'pci_unidades',
        'pci_valor_cheio',
        'pci_desconto',
    ];

    protected function casts(): array
    {
        return [
            'pci_unidades' => 'decimal:2',
            'pci_valor_cheio' => 'decimal:2',
            'pci_desconto' => 'decimal:2',
        ];
    }

    public function aplicacao(): BelongsTo
    {
        return $this->belongsTo(PedidoComboAplicacao::class, 'pci_aplicacao_id');
    }

    public function itemPedido(): BelongsTo
    {
        return $this->belongsTo(ItensPedido::class, 'pci_item_pedido_id');
    }
}
