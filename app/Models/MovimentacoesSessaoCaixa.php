<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MovimentacoesSessaoCaixa extends Model
{
    use HasFactory, SoftDeletes;

    // Nome da tabela
    protected $table = 'movimentacoes_sessao_caixas';

    // Campos preenchíveis
    protected $fillable = [
        'mov_sessaocaixa_id',
        'mov_venda_id',
        'mov_descricao',
        'mov_tipo',
        'mov_valor',
        'mov_observacoes'
    ];

    // Relacionamento com a tabela `sessao_caixas`
    public function sessaoCaixa()
    {
        return $this->belongsTo(SessaoCaixa::class, 'mov_sessaocaixa_id');
    }

    // Relacionamento com a tabela `vendas`
    public function venda()
    {
        return $this->belongsTo(Venda::class, 'mov_venda_id');
    }
}
