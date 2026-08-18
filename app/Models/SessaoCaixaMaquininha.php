<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessaoCaixaMaquininha extends Model
{
    protected $table = 'sessao_caixa_maquininhas';

    protected $fillable = [
        'sessao_caixa_id',
        'maquininha_id',
        'saldo_inicial',
    ];

    protected function casts(): array
    {
        return [
            'saldo_inicial' => 'decimal:2',
        ];
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    public function maquininha(): BelongsTo
    {
        return $this->belongsTo(Maquininha::class, 'maquininha_id');
    }
}
