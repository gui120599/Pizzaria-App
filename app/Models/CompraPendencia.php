<?php

namespace App\Models;

use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Algo da automação de compras que a regra não decidiu sozinha (produto sem
 * de-para, lançamento ambíguo, caixa fechado...). Criada e resolvida só pelo
 * App\Services\Compras\PendenciaService.
 */
class CompraPendencia extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'compra_pendencias';

    protected $fillable = [
        'compra_id',
        'compra_item_id',
        'tipo',
        'status',
        'dados',
        'sugestao',
        'confianca',
        'justificativa',
        'resolucao',
        'resolvida_por',
        'resolvida_em',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPendenciaCompra::class,
            'status' => StatusPendencia::class,
            'dados' => 'array',
            'sugestao' => 'array',
            'resolucao' => 'array',
            'confianca' => 'decimal:3',
            'resolvida_em' => 'datetime',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CompraItem::class, 'compra_item_id');
    }

    public function resolvidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvida_por');
    }

    public function scopeEmAberto(Builder $query): Builder
    {
        return $query->whereIn('status', StatusPendencia::emAberto());
    }

    public function scopeDoTipo(Builder $query, TipoPendenciaCompra $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public function estaEmAberto(): bool
    {
        return in_array($this->status, StatusPendencia::emAberto(), true);
    }
}
