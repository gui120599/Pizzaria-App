<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger de crédito de um fornecedor (Prestador), originado por devolução de
 * compra. Saldo disponível = soma de `valor` onde aplicado_em_lancamento_id
 * é nulo. Ver App\Services\CompraDevolucaoService (cria o crédito) e
 * App\Services\CompraService::gerarContaPagar (aplica o crédito).
 */
class PrestadorCredito extends Model
{
    protected $table = 'prestador_creditos';

    protected $fillable = [
        'prestador_id',
        'origem_tipo',
        'origem_id',
        'valor',
        'aplicado_em_lancamento_id',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
        ];
    }

    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class, 'prestador_id');
    }

    public function lancamentoAplicado(): BelongsTo
    {
        return $this->belongsTo(Lancamento::class, 'aplicado_em_lancamento_id');
    }

    public function scopeDoPrestador(Builder $query, int $prestadorId): Builder
    {
        return $query->where('prestador_id', $prestadorId);
    }

    public function scopeNaoAplicados(Builder $query): Builder
    {
        return $query->whereNull('aplicado_em_lancamento_id');
    }
}
