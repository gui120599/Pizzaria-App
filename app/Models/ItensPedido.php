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
        'item_pedido_quantidade',
        'item_pedido_valor_unitario',
        'item_pedido_desconto',
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
}
