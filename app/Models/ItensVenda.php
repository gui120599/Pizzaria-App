<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItensVenda extends Model
{
    use HasFactory;
    protected $table = 'itens_vendas';

    protected $fillable = [
        'item_numero',
        'item_venda_venda_id',
        'item_venda_produto_id',
        'item_venda_quantidade',
        'item_venda_quantidade_tributavel',
        'item_venda_valor_unitario',
        'item_venda_valor_unitario_tributavel',
        'item_venda_desconto',
        'item_venda_valor',
        'item_venda_base_calculo_icms',
        'item_venda_valor_icms',
        'item_venda_valor_total_tributos',
        'item_venda_observacao',
        'item_venda_status',
        'item_venda_usuario_removeu'
    ];

    protected $casts = [
        'item_venda_quantidade' => 'double',
        'item_venda_quantidade_tributavel' => 'double',
        'item_venda_valor_unitario' => 'decimal:2',
        'item_venda_valor_unitario_tributavel' => 'decimal:2',
        'item_venda_desconto' => 'decimal:2',
        'item_venda_valor' => 'decimal:2',
        'item_venda_base_calculo_icms' => 'decimal:2',
        'item_venda_valor_icms' => 'decimal:2',
        'item_venda_valor_total_tributos' => 'decimal:2',
    ];

    public function venda()
    {
        return $this->belongsTo(Venda::class, 'item_venda_venda_id');
    }

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'item_venda_produto_id');
    }

    public function usuarioRemoveu()
    {
        return $this->belongsTo(User::class, 'item_venda_usuario_removeu');
    }
}
