<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CartoesPagamento extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cartoes_pagamentos';

    protected $fillable = [
        'cartao_bandeira',
        'cartao_cnpj_credenciadora'
    ];

    protected $dates = ['deleted_at'];

    const BANDEIRAS = [
        'Visa' => 'visa',
        'MasterCard' => 'mastercard',
        'American Express' => 'americanExpress',
        'Sorocred' => 'sorocred',
        'Outro' => 'other'
    ];

    public function getCartaoBandeiraAttribute($value)
    {
        return array_search($value, self::BANDEIRAS);
    }

    public function setCartaoBandeiraAttribute($value)
    {
        $this->attributes['cartao_bandeira'] = self::BANDEIRAS[$value] ?? 'other';
    }
}
