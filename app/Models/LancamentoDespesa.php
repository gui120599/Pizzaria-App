<?php

namespace App\Models;

use App\Enums\Comportamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha de rateio de um lançamento (a pagar) por plano de despesa.
 * Ver [[project_modulo_financeiro]]: 1 lançamento -> N despesas.
 */
class LancamentoDespesa extends Model
{
    protected $table = 'lancamento_despesas';

    protected $fillable = [
        'lancamento_id',
        'plano_despesa_id',
        'valor',
        'comportamento',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'comportamento' => Comportamento::class,
        ];
    }

    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(Lancamento::class, 'lancamento_id');
    }

    public function planoDespesa(): BelongsTo
    {
        return $this->belongsTo(PlanoDespesa::class, 'plano_despesa_id');
    }
}
