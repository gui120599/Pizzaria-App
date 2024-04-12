<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SessaoMesa extends Model
{
    use HasFactory;

    protected $fillable = [
        'sessao_mesa_mesa_id',
        'sessao_mesa_cliente_id',
        'sessao_mesa_usuario_id',
        'sessao_mesa_status',
        'sessao_mesa_motivo_cancelamento',
    ];

    protected $casts = [
        'sessao_mesa_status' => 'string',
    ];

    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'sessao_mesa_mesa_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'sessao_mesa_cliente_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'sessao_mesa_usuario_id');
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'pedido_sessao_mesa_id');
    }
}
