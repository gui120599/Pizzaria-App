<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Código de 6 dígitos (uso único, 15 min) que prova a identidade do influenciador no caixa. */
class CodigoResgate extends Model
{
    protected $table = 'codigos_resgate';

    protected $fillable = [
        'cr_influenciador_id',
        'cr_codigo_hash',
        'cr_expira_em',
        'cr_usado_em',
        'cr_pagamento_venda_id',
    ];

    protected function casts(): array
    {
        return [
            'cr_expira_em' => 'datetime',
            'cr_usado_em' => 'datetime',
        ];
    }

    public function influenciador()
    {
        return $this->belongsTo(Influenciador::class, 'cr_influenciador_id');
    }

    public function estaVigente(): bool
    {
        return $this->cr_usado_em === null && $this->cr_expira_em->isFuture();
    }
}
