<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    use HasFactory;

    protected $fillable = [
        'pedido_cliente_id',
        'pedido_sessao_mesa_id',
        'pedido_usuario_garcom_id',
        'pedido_usuario_entrega_id',
        'pedido_opcaoentrega_id',
        'pedido_descricao_pagamento',
        'pedido_observacao_pagamento',
        'pedido_endereco_entrega',
        'pedido_valor_itens',
        'pedido_valor_desconto',
        'pedido_valor_total',
        'pedido_status',
        'pedido_datahora_abertura',
        'pedido_datahora_preparo',
        'pedido_datahora_retirada',
        'pedido_datahora_transporte',
        'pedido_datahora_entrega',
        'pedido_datahora_finalizado',
        'pedido_datahora_cancelado',
    ];

    protected $casts = [
        'pedido_datahora_abertura' => 'datetime',
        'pedido_datahora_preparo' => 'datetime',
        'pedido_datahora_retirada' => 'datetime',
        'pedido_datahora_transporte' => 'datetime',
        'pedido_datahora_entrega' => 'datetime',
        'pedido_datahora_finalizado' => 'datetime',
        'pedido_datahora_cancelado' => 'datetime',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'pedido_cliente_id')->withDefault([
            'cliente_nome' => 'Sem cliente'
        ]);
    }

    public function sessaoMesa()
    {
        return $this->belongsTo(SessaoMesa::class, 'pedido_sessao_mesa_id')->withDefault([
            'mesa_nome' => 'Sem mesa'
        ]);
    }

    public function garcom()
    {
        return $this->belongsTo(User::class, 'pedido_usuario_garcom_id')->withDefault([
            'name' => 'Sem Garçom'
        ]);;
    }

    public function entregador()
    {
        return $this->belongsTo(User::class, 'pedido_usuario_entrega_id')->withDefault([
            'name' => 'Sem Garçom'
        ]);;
    }

    public function opcaoEntrega()
    {
        return $this->belongsTo(OpcoesEntregas::class, 'pedido_opcaoentrega_id');
    }

    public function produtos()
    {
        return $this->belongsToMany(Produto::class, 'itens_pedidos', 'item_pedido_pedido_id', 'item_pedido_produto_id')
            ->withPivot('quantidade', 'valor', 'observacao', 'status', 'usuario_removeu');
    }
}
