<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpcoesEntregas extends Model
{
    use HasFactory,SoftDeletes;

    protected $table = 'opcoes_entregas';

    protected $fillable = [
        'opcaoentrega_nome',
    ];
}
