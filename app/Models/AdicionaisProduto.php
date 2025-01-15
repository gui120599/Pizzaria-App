<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdicionaisProduto extends Model
{
    use HasFactory,SoftDeletes;

    protected $table = 'adicionais_produtos';

    protected $fillable = [
        'ap_adicional_id',
        'ap_produto_id'
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function adicional(){
       return $this->belongsTo(Adicional::class,'ap_adicional_id');
    }
    public function produto(){
        return $this->belongsTo(Produto::class,'ap_produto_id');
    }
}
