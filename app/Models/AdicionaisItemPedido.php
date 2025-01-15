<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdicionaisItemPedido extends Model
{
    use HasFactory;

    // Nome da tabela
    protected $table = 'adicionais_item_pedidos';

    // Colunas que podem ser preenchidas via mass assignment
    protected $fillable = [
        'aip_adicional_id',
        'aip_item_pedido_id',
        'aip_quantidade',
        'aip_valor_unitario',
        'aip_valor_total',
    ];

    /**
     * Relacionamento com o modelo Adicional
     */
    public function adicional()
    {
        return $this->belongsTo(Adicional::class, 'aip_adicional_id');
    }

    /**
     * Relacionamento com o modelo ItensPedido
     */
    public function itemPedido()
    {
        return $this->belongsTo(ItensPedido::class, 'aip_item_pedido_id');
    }
}
