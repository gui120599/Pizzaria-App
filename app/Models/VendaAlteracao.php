<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma alteração completa de venda finalizada: aberta (venda reaberta no PDV),
 * concluída (finalizada de novo), descartada (restaurada) ou cancelada (a
 * venda foi cancelada durante a alteração). Ver AlteracaoVendaService.
 */
class VendaAlteracao extends Model
{
    public const ABERTA = 'aberta';

    public const CONCLUIDA = 'concluida';

    public const DESCARTADA = 'descartada';

    public const CANCELADA = 'cancelada';

    protected $table = 'venda_alteracoes';

    protected $fillable = [
        'venda_id',
        'user_id',
        'motivo',
        'status',
        'antes',
        'depois',
        'encerrada_em',
    ];

    protected function casts(): array
    {
        return [
            'antes' => 'array',
            'depois' => 'array',
            'encerrada_em' => 'datetime',
        ];
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function encerrar(string $status, ?array $depois = null): void
    {
        $this->update(['status' => $status, 'depois' => $depois, 'encerrada_em' => now()]);
    }
}
