<?php

namespace App\Models;

use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use Illuminate\Database\Eloquent\Builder;
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
        'mov_forma_pagamento',
        'mov_motivo',
        'mov_user_id',
        'mov_valor',
        'mov_observacoes',
    ];

    protected function casts(): array
    {
        return [
            'mov_forma_pagamento' => FormaPagamento::class,
            'mov_motivo' => MotivoSaidaCaixa::class,
        ];
    }

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

    /** Quem registrou a sangria/suprimento. */
    public function user()
    {
        return $this->belongsTo(User::class, 'mov_user_id');
    }

    /**
     * Movimentos manuais (sangria/suprimento) — mov_motivo preenchido. Isola o
     * que o operador lançou do que o sistema gera sozinho (venda, abertura,
     * estorno Stone), que sempre ficam com mov_motivo nulo.
     */
    public function scopeManuais(Builder $query): Builder
    {
        return $query->whereNotNull('mov_motivo');
    }
}
