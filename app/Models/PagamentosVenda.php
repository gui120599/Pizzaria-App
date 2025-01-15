<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PagamentosVenda extends Model
{
    use HasFactory;

    protected $table = 'pagamentos_vendas';

    protected $fillable = [
        'pg_venda_venda_id',
        'pg_venda_opcaopagamento_id',
        'pg_venda_cartao_id',
        'pg_venda_numero_autorizacao_cartao',
        'pg_venda_tipo_integracao',
        'pg_venda_valor_pagamento',
        'pg_venda_valor_acrescimo',
        'pg_venda_valor_desconto',
    ];

    protected $casts = [
        'pg_venda_valor_pagamento' => 'decimal:2',
        'pg_venda_valor_acrescimo' => 'decimal:2',
        'pg_venda_valor_desconto' => 'decimal:2',
    ];

    public function venda()
    {
        return $this->belongsTo(Venda::class, 'pg_venda_venda_id');
    }

    public function opcaoPagamento()
    {
        return $this->belongsTo(OpcoesPagamento::class, 'pg_venda_opcaopagamento_id');
    }

    public function cartao()
    {
        return $this->belongsTo(CartoesPagamento::class, 'pg_venda_cartao_id');
    }
}
