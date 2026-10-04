<?php

namespace App\Models;

use App\Enums\TipoPagamentoMaquininhaEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Taxa da adquirente (MDR) de uma maquininha por bandeira e tipo. Sem
 * bandeira = vale para qualquer bandeira daquele tipo.
 */
class MaquininhaTaxa extends Model
{
    protected $table = 'maquininha_taxas';

    protected $fillable = [
        'mt_maquininha_id',
        'mt_cartao_id',
        'mt_tipo',
        'mt_percentual',
    ];

    protected function casts(): array
    {
        return [
            'mt_tipo' => TipoPagamentoMaquininhaEnum::class,
            'mt_percentual' => 'decimal:2',
        ];
    }

    public function maquininha(): BelongsTo
    {
        return $this->belongsTo(Maquininha::class, 'mt_maquininha_id');
    }

    public function cartao(): BelongsTo
    {
        return $this->belongsTo(CartoesPagamento::class, 'mt_cartao_id');
    }
}
