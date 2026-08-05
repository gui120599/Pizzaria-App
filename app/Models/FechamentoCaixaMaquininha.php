<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FechamentoCaixaMaquininha extends Model
{
    protected $table = 'fechamento_caixa_maquininhas';

    protected $fillable = [
        'fechamento_caixa_id',
        'maquininha_id',
        'valor_debito',
        'valor_credito',
        'valor_pix',
        'saldo_inicial',
    ];

    protected function casts(): array
    {
        return [
            'valor_debito' => 'decimal:2',
            'valor_credito' => 'decimal:2',
            'valor_pix' => 'decimal:2',
            'saldo_inicial' => 'decimal:2',
        ];
    }

    public function fechamentoCaixa(): BelongsTo
    {
        return $this->belongsTo(FechamentoCaixa::class, 'fechamento_caixa_id');
    }

    public function maquininha(): BelongsTo
    {
        return $this->belongsTo(Maquininha::class, 'maquininha_id');
    }
}
