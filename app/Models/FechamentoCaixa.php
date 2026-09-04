<?php

namespace App\Models;

use App\Enums\StatusFechamentoCaixa;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FechamentoCaixa extends Model
{
    use SoftDeletes;

    protected $table = 'fechamentos_caixa';

    protected $fillable = [
        'sessao_caixa_id',
        'user_id',
        'status',
        'total_esperado_dinheiro',
        'total_esperado_debito',
        'total_esperado_credito',
        'total_esperado_pix',
        'total_esperado_outros',
        'confirmado_em',
        'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusFechamentoCaixa::class,
            'total_esperado_dinheiro' => 'decimal:2',
            'total_esperado_debito' => 'decimal:2',
            'total_esperado_credito' => 'decimal:2',
            'total_esperado_pix' => 'decimal:2',
            'total_esperado_outros' => 'decimal:2',
            'confirmado_em' => 'datetime',
        ];
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function notas(): HasMany
    {
        return $this->hasMany(FechamentoCaixaNota::class, 'fechamento_caixa_id');
    }

    public function maquininhas(): HasMany
    {
        return $this->hasMany(FechamentoCaixaMaquininha::class, 'fechamento_caixa_id');
    }

    /** Soma o dinheiro contado (usa notas_sum_valor_total se pré-carregado via withSum). */
    protected function totalDinheiroContado(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) ($this->notas_sum_valor_total ?? $this->notas()->sum('valor_total')),
        );
    }

    protected function totalDebito(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->totalPorCategoria('valor_debito'),
        );
    }

    protected function totalCredito(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->totalPorCategoria('valor_credito'),
        );
    }

    protected function totalPix(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->totalPorCategoria('valor_pix'),
        );
    }

    /**
     * Bruto lido na maquininha (leitura cumulativa desde sempre) menos o
     * carryover da MESMA maquininha registrado na abertura (SessaoCaixaMaquininha),
     * por categoria — não só do total geral como antes. Corrige um bug latente:
     * antes só o total geral subtraía o saldo inicial, então "Débito"/"Crédito"/
     * "Pix" sozinhos podiam mostrar sobra falsa mesmo com o total batendo certo.
     */
    private function totalPorCategoria(string $coluna): float
    {
        $carryoverPorMaquininha = $this->sessaoCaixa->maquininhas->keyBy('maquininha_id');

        return (float) $this->maquininhas->sum(
            fn (FechamentoCaixaMaquininha $m): float => (float) $m->{$coluna}
                - (float) ($carryoverPorMaquininha->get($m->maquininha_id)?->{$coluna} ?? 0),
        );
    }

    /** Já líquido — a subtração do carryover acontece por categoria em totalPorCategoria(). */
    protected function totalMaquininhasLiquido(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->totalDebito + $this->totalCredito + $this->totalPix,
        );
    }

    protected function totalApurado(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->totalDinheiroContado + $this->totalMaquininhasLiquido,
        );
    }

    protected function totalEsperadoGeral(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->total_esperado_dinheiro
                + (float) $this->total_esperado_debito
                + (float) $this->total_esperado_credito
                + (float) $this->total_esperado_pix
                + (float) $this->total_esperado_outros,
        );
    }

    protected function diferencaGeral(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->totalApurado - $this->totalEsperadoGeral, 2),
        );
    }

    protected function diferencaDinheiro(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->totalDinheiroContado - (float) $this->total_esperado_dinheiro, 2),
        );
    }

    protected function diferencaDebito(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->totalDebito - (float) $this->total_esperado_debito, 2),
        );
    }

    protected function diferencaCredito(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->totalCredito - (float) $this->total_esperado_credito, 2),
        );
    }

    protected function diferencaPix(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->totalPix - (float) $this->total_esperado_pix, 2),
        );
    }
}
