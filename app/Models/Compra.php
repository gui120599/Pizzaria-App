<?php

namespace App\Models;

use App\Enums\CompraStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Compra extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'compras';

    protected $fillable = [
        'compra_prestador_id',
        'compra_numero',
        'compra_serie',
        'compra_chave_nfe',
        'compra_xml_path',
        'compra_data_emissao',
        'compra_data_entrada',
        'compra_valor_produtos',
        'compra_valor_frete',
        'compra_valor_desconto',
        'compra_valor_outros',
        'compra_valor_total',
        'compra_status',
        'compra_origem',
        'compra_centro_custo_id',
        'compra_observacao',
        'compra_user_id',
        'compra_confirmada_em',
    ];

    protected function casts(): array
    {
        return [
            'compra_status' => CompraStatusEnum::class,
            'compra_data_emissao' => 'date',
            'compra_data_entrada' => 'date',
            'compra_confirmada_em' => 'datetime',
            'compra_valor_produtos' => 'decimal:2',
            'compra_valor_frete' => 'decimal:2',
            'compra_valor_desconto' => 'decimal:2',
            'compra_valor_outros' => 'decimal:2',
            'compra_valor_total' => 'decimal:2',
        ];
    }

    public function devolucoes(): HasMany
    {
        return $this->hasMany(CompraDevolucao::class, 'compra_id');
    }

    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class, 'compra_prestador_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CompraItem::class, 'ci_compra_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'compra_centro_custo_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compra_user_id');
    }

    public function movimentacoes(): MorphMany
    {
        return $this->morphMany(MovimentacaoProduto::class, 'referencia', 'mov_referencia_type', 'mov_referencia_id');
    }

    /** Títulos a pagar (parcelas) gerados a partir desta compra. */
    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class, 'compra_id');
    }

    public function isRascunho(): bool
    {
        return $this->compra_status === CompraStatusEnum::RASCUNHO;
    }

    /** Acréscimos rateáveis sobre os itens (frete + outros - desconto). */
    public function valorRateavel(): float
    {
        return (float) $this->compra_valor_frete
            + (float) $this->compra_valor_outros
            - (float) $this->compra_valor_desconto;
    }
}
