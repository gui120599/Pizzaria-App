<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpcoesPagamento extends Model
{
    use HasFactory,SoftDeletes;

    protected $table = 'opcoes_pagamentos';

    protected $fillable = [
        'opcaopag_nome',
        'opcaopag_tipo_taxa',
        'opcaopag_valor_percentual_taxa',
    ];

    protected $casts = [
        'opcaopag_valor_percentual_taxa' => 'decimal:2',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];
}
