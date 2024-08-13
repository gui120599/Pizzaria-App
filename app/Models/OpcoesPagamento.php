<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpcoesPagamento extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'opcoes_pagamentos';

    protected $fillable = [
        'opcaopag_nome',
        'opcaopag_tipo_taxa',
        'opcaopag_desc_nfe',
        'opcaopag_valor_percentual_taxa'
    ];

    protected $casts = [
        'opcaopag_tipo_taxa' => 'string',
        'opcaopag_desc_nfe' => 'string',
        'opcaopag_valor_percentual_taxa' => 'float'
    ];

    const TIPO_TAXA = [
        'N/A' => 'N/A',
        'DESCONTAR' => 'DESCONTAR',
        'ACRESCENTAR' => 'ACRESCENTAR'
    ];

    const DESC_NFE = [
        'dinheiro' => 'cash',
        'cheque' => 'cheque',
        'cartão de crédito' => 'creditCard',
        'cartão de débito' => 'debitCard',
        'crédito em loja' => 'storeCredict',
        'vales alimentação' => 'foodVouchers',
        'vales refeição' => 'mealVouchers',
        'vales presente' => 'giftVouchers',
        'vales combustível' => 'fuelVouchers',
        'boleto bancário' => 'bankBill',
        'sem pagamento' => 'withoutPayment',
        'outros' => 'others'
    ];

    // Accessor for `opcaopag_desc_nfe` to ensure valid values
    public function getOpcaoPagDescNfeAttribute($value)
    {
        return array_search($value, self::DESC_NFE) ?: 'outros';
    }

    // Mutator for `opcaopag_desc_nfe` to ensure valid values
    public function setOpcaoPagDescNfeAttribute($value)
    {
        $this->attributes['opcaopag_desc_nfe'] = self::DESC_NFE[$value] ?? 'others';
    }

    public function pagamentosVenda()
    {
        return $this->hasMany(PagamentosVenda::class, 'pg_venda_opcaopagamento_id', 'id');
    }

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];
}
