<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma opção de produto que pode ser oferecida para o gatilho da regra-mãe
 * (ex.: "Brotinho +R$5" ou "Refrigerante +R$3" para a mesma pizza). Preço e
 * saldo vivem aqui — cada opção pode ter teto e valor independentes.
 */
class PromocaoAdicionalOferta extends Model
{
    use HasFactory;

    protected $table = 'promocao_adicional_ofertas';

    protected $fillable = [
        'pao_regra_id',
        'pao_produto_oferta_id',
        'pao_valor_adicional',
        'pao_qtd_total',
        'pao_qtd_vendida',
    ];

    protected function casts(): array
    {
        return [
            'pao_valor_adicional' => 'decimal:2',
            'pao_qtd_total' => 'integer',
            'pao_qtd_vendida' => 'decimal:2',
        ];
    }

    public function regra(): BelongsTo
    {
        return $this->belongsTo(PromocaoAdicionalRegra::class, 'pao_regra_id');
    }

    public function produtoOferta(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'pao_produto_oferta_id');
    }

    /** Unidades restantes do teto desta oferta; null quando não há teto. */
    public function saldoDisponivel(): ?float
    {
        if ($this->pao_qtd_total === null) {
            return null;
        }

        return max(0.0, (float) $this->pao_qtd_total - (float) $this->pao_qtd_vendida);
    }

    public function esgotada(): bool
    {
        $saldo = $this->saldoDisponivel();

        return $saldo !== null && $saldo <= 0;
    }
}
