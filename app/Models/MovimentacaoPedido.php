<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MovimentacaoPedido extends Model
{
    /** @use HasFactory<\Database\Factories\MovimentacaoPedidoFactory> */
    use HasFactory,SoftDeletes;

    protected $table = 'movimentacao_pedidos';

    protected $fillable = [
        'mov_pedido_pedido_id',
        'mov_pedido_sessao_mesa_id_anterior',
        'mov_pedido_sessao_mesa_id_atual',
        'mov_pedido_user_id',
    ];

    /**
     * Relação com o pedido.
     */
    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'mov_pedido_pedido_id');
    }

    /**
     * Relação com a sessão/mesa anterior.
     */
    public function sessaoMesaAntiga()
    {
        return $this->belongsTo(SessaoMesa::class, 'mov_pedido_sessao_mesa_id_anterior')->withDefault('S/Mesa');
    }

    /**
     * Relação com a nova sessão/mesa.
     */
    public function sessaoMesaAtual()
    {
        return $this->belongsTo(SessaoMesa::class, 'mov_pedido_sessao_mesa_id_atual')->withDefault('S/Mesa');
    }

    /**
     * Relação com o usuário que fez a alteração.
     */
    public function usuario()
    {
        return $this->belongsTo(User::class, 'mov_pedido_user_id');
    }
}
