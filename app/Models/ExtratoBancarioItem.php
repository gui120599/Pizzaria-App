<?php

namespace App\Models;

use App\Enums\Financeiro\StatusConciliacaoExtrato;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha do extrato bancário importada por API. Valores nunca mudam depois
 * de gravados: só o status de conciliação e o vínculo com a movimentação.
 */
class ExtratoBancarioItem extends Model
{
    protected $table = 'extrato_bancario_itens';

    protected $fillable = [
        'conta_financeira_id',
        'data_lancamento',
        'data_movimento',
        'valor',
        'natureza',
        'historico',
        'documento',
        'info_complementar',
        'hash_unico',
        'status_conciliacao',
        'movimentacao_financeira_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'data_lancamento' => 'date',
            'data_movimento' => 'date',
            'valor' => 'decimal:2',
            'status_conciliacao' => StatusConciliacaoExtrato::class,
            'payload' => 'array',
        ];
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class);
    }

    public function movimentacao(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoFinanceira::class, 'movimentacao_financeira_id');
    }

    public function scopeDebitos(Builder $query): Builder
    {
        return $query->where('natureza', 'D');
    }

    public function ehDebito(): bool
    {
        return $this->natureza === 'D';
    }
}
