<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProdutoPrecoHistorico extends Model
{
    use HasFactory;

    protected $table = 'produto_precos_historicos';

    protected $fillable = [
        'lote_uuid',
        'acao',
        'categoria_id',
        'produto_id',
        'usuario_id',
        'valor_antigo_custo',
        'valor_antigo_percentual',
        'valor_antigo_venda',
        'valor_novo_custo',
        'valor_novo_percentual',
        'valor_novo_venda',
        'restaurado_em',
        'restaurado_por',
    ];
}
