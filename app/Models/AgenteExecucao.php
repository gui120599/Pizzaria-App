<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma rodada do agente de compras, gravada pela tool registrar_resumo. */
class AgenteExecucao extends Model
{
    protected $table = 'agente_execucoes';

    protected $fillable = [
        'user_id',
        'iniciada_em',
        'finalizada_em',
        'pendencias_resolvidas',
        'pendencias_sugeridas',
        'resumo',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_em' => 'datetime',
            'finalizada_em' => 'datetime',
            'pendencias_resolvidas' => 'integer',
            'pendencias_sugeridas' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
