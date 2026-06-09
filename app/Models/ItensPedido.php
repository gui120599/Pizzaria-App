<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItensPedido extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_pedido_produto_id',
        'item_pedido_pedido_id',
        'item_pedido_cliente_id',
        'item_pedido_venda_id',
        'item_pedido_quantidade',
        'item_pedido_valor_unitario',
        'item_pedido_desconto',
        'item_pedido_valor_adicionais',
        'item_pedido_valor',
        'item_pedido_observacao',
        'item_pedido_status',
        'item_pedido_usuario_removeu',
    ];

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'item_pedido_produto_id');
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'item_pedido_pedido_id');
    }

    public function usuarioRemoveu()
    {
        return $this->belongsTo(User::class, 'item_pedido_usuario_removeu');
    }

    public function adicionaisItemPedido(){
        return $this->hasMany( AdicionaisItemPedido::class, 'aip_item_pedido_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'item_pedido_cliente_id');
    }

    public function venda()
    {
        return $this->belongsTo(\App\Models\Venda::class, 'item_pedido_venda_id');
    }
}
