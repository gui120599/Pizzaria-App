<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de auditoria de uma correção retroativa de estoque/custo médio
 * feita via CorrecaoEstoqueService — guarda o antes/depois para rastreio,
 * já que a correção reescreve custo médio, movimentações e, às vezes, o
 * custo já congelado de vendas passadas.
 */
class EstoqueCorrecao extends Model
{
    protected $table = 'estoque_correcoes';

    protected $fillable = [
        'ec_produto_id',
        'ec_movimentacao_id',
        'ec_user_id',
        'ec_motivo',
        'ec_dados_antes',
        'ec_dados_depois',
    ];

    protected function casts(): array
    {
        return [
            'ec_dados_antes' => 'array',
            'ec_dados_depois' => 'array',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'ec_produto_id');
    }

    public function movimentacao(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoProduto::class, 'ec_movimentacao_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ec_user_id');
    }
}
