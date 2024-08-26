<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SessaoCaixa extends Model
{
    use HasFactory,SoftDeletes;

    protected $table = 'sessao_caixas';

    protected $fillable = [
        'sessaocaixa_caixa_id',
        'sessaocaixa_status',
        'sessaocaixa_data_hora_abertura',
        'sessaocaixa_data_hora_fechamento',
        'sessaocaixa_saldo_inicial',
        'sessaocaixa_saldo_final',
        'sessaocaixa_user_id',
        'sessaocaixa_observacoes',
    ];

    protected $casts = [
        'sessaocaixa_data_hora_abertura' => 'datetime',
        'sessaocaixa_data_hora_fechamento' => 'datetime',
        'created_at'=> 'datetime',
        'updated_at'=> 'datetime',
        'deleted_at'=> 'datetime',
    ];

    // Relacionamento com a tabela 'caixas'
    public function caixa()
    {
        return $this->belongsTo(Caixa::class, 'sessaocaixa_caixa_id');
    }

    // Relacionamento com a tabela 'users'
    public function user()
    {
        return $this->belongsTo(User::class, 'sessaocaixa_user_id');
    }
    public function vendas()
{
    return $this->hasMany(Venda::class, 'venda_sessao_caixa_id');
}

}
