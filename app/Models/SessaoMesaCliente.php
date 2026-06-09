<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessaoMesaCliente extends Model
{
    protected $fillable = ['smc_sessao_mesa_id', 'smc_cliente_id'];

    public function sessaoMesa()
    {
        return $this->belongsTo(SessaoMesa::class, 'smc_sessao_mesa_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'smc_cliente_id');
    }
}
