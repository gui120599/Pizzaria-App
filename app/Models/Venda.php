<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venda extends Model
{
    use HasFactory;

    protected $table = 'vendas';

    protected $fillable = [
        'venda_sessao_caixa_id',
        'venda_cliente_id',
        'venda_valor_base_calculo_icms',
        'venda_valor_icms',
        'venda_valor_pis',
        'venda_valor_cofins',
        'venda_valor_frete',
        'venda_valor_seguro',
        'venda_valor_itens',
        'venda_valor_desconto',
        'venda_valor_total',
        'venda_valor_pago',
        'venda_valor_troco',
        'venda_status',
        'venda_datahora_iniciada',
        'venda_datahora_finalizada',
    ];

    protected $casts = [
        'venda_valor_base_calculo_icms' => 'decimal:2',
        'venda_valor_icms' => 'decimal:2',
        'venda_valor_pis' => 'decimal:2',
        'venda_valor_cofins' => 'decimal:2',
        'venda_valor_frete' => 'decimal:2',
        'venda_valor_seguro' => 'decimal:2',
        'venda_valor_itens' => 'decimal:2',
        'venda_valor_desconto' => 'decimal:2',
        'venda_valor_total' => 'decimal:2',
        'venda_valor_pago' => 'decimal:2',
        'venda_valor_troco' => 'decimal:2',
        'venda_datahora_iniciada' => 'datetime',
        'venda_datahora_finalizada' => 'datetime',
    ];

    public function sessaoCaixa()
    {
        return $this->belongsTo(SessaoCaixa::class, 'venda_sessao_caixa_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'venda_cliente_id');
    }

    public function itensVenda()
    {
        return $this->hasMany(ItensVenda::class, 'item_venda_venda_id');
    }
    
}
