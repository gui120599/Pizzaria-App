<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompraDevolucao extends Model
{
    protected $table = 'compra_devolucoes';

    protected $fillable = [
        'compra_id',
        'motivo',
        'valor_total',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'valor_total' => 'decimal:2',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CompraDevolucaoItem::class, 'compra_devolucao_id');
    }
}
