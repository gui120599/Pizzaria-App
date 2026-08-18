<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessaoCaixaNota extends Model
{
    protected $table = 'sessao_caixa_notas';

    protected $fillable = [
        'sessao_caixa_id',
        'nota_moeda_id',
        'quantidade',
        'valor_total',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'integer',
            'valor_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // valor_total é sempre um snapshot de quantidade x notaMoeda.valor.
        static::saving(function (SessaoCaixaNota $item): void {
            $notaMoeda = $item->notaMoeda ?? $item->notaMoeda()->first();

            $item->valor_total = round((float) $item->quantidade * (float) ($notaMoeda?->valor ?? 0), 2);
        });
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    public function notaMoeda(): BelongsTo
    {
        return $this->belongsTo(NotaMoeda::class, 'nota_moeda_id');
    }
}
