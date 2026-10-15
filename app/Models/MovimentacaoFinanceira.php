<?php

namespace App\Models;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Exceptions\RegistroImutavelException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Entrada/saída de dinheiro real numa conta. Append-only: correção é por
 * estorno (movimentação inversa com `estorno_de_id` apontando para esta).
 * Só o MovimentacaoService cria registros.
 */
class MovimentacaoFinanceira extends Model
{
    use HasFactory;

    protected $table = 'movimentacoes_financeiras';

    public const UPDATED_AT = null;

    protected $fillable = [
        'conta_financeira_id',
        'tipo',
        'natureza',
        'valor',
        'data_movimento',
        'descricao',
        'lancamento_id',
        'lancamento_pagamento_id',
        'adiantamento_id',
        'sessao_caixa_id',
        'transferencia_uuid',
        'estorno_de_id',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimentacao::class,
            'natureza' => NaturezaMovimentacao::class,
            'valor' => 'decimal:2',
            'data_movimento' => 'date',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw RegistroImutavelException::alteracao('A movimentação financeira', 'Faça um estorno.');
        });

        static::deleting(function (): void {
            throw RegistroImutavelException::alteracao('A movimentação financeira', 'Faça um estorno.');
        });
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id')->withTrashed();
    }

    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(Lancamento::class, 'lancamento_id');
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(LancamentoPagamento::class, 'lancamento_pagamento_id');
    }

    public function adiantamento(): BelongsTo
    {
        return $this->belongsTo(Adiantamento::class, 'adiantamento_id')->withTrashed();
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    /** A movimentação original que esta estorna. */
    public function estornoDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'estorno_de_id');
    }

    /** O estorno desta movimentação, se houver. */
    public function estorno(): HasOne
    {
        return $this->hasOne(self::class, 'estorno_de_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /** Nem foram estornadas nem são estornos. */
    public function scopeEfetivas(Builder $query): Builder
    {
        return $query->whereNull('estorno_de_id')->whereDoesntHave('estorno');
    }

    public function valorComSinal(): float
    {
        return $this->tipo->sinal() * (float) $this->valor;
    }
}
