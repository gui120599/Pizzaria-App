<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdicionaisItemVenda extends Model
{
    use HasFactory;

    // Nome da tabela
    protected $table = 'adicionais_item_vendas';

    // Colunas que podem ser preenchidas via mass assignment
    protected $fillable = [
        'aiv_adicional_id',
        'aiv_item_venda_id',
        'aiv_quantidade',
        'aiv_valor_unitario',
        'aiv_valor_total',
    ];

    /**
     * Relacionamento com o modelo Adicional
     */
    public function adicional()
    {
        return $this->belongsTo(Adicional::class, 'aiv_adicional_id');
    }

    /**
     * Relacionamento com o modelo ItemVenda
     */
    public function itemVenda()
    {
        return $this->belongsTo(ItensVenda::class, 'aiv_item_venda_id');
    }
}
