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
        'valor_debito',
        'valor_credito',
        'valor_pix',
    ];

    protected function casts(): array
    {
        return [
            'valor_debito' => 'decimal:2',
            'valor_credito' => 'decimal:2',
            'valor_pix' => 'decimal:2',
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
